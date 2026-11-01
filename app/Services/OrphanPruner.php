<?php

namespace App\Services;

use App\Models\SubtitleLookup;
use App\Models\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Finds and removes what no longer belongs to anything: HLS folders no playlist points at, subtitles whose video is
 * gone, stale partial files, and database records for folders/videos that no longer exist.
 *
 * Deletions made outside the app (a shell, an FTP client) leave exactly these behind and, on a nearly full disk,
 * they are not harmless. Nothing recent is touched, so a conversion that is still being written is safe.
 */
class OrphanPruner
{
    /** A partial download/conversion file younger than this may belong to a running job. */
    private const PARTIAL_MIN_AGE_DAYS = 1;

    /**
     * What would be removed.
     *
     * @param  int  $graceDays  keep orphans touched within this many days (0: everything unreferenced right now)
     * @return array{hls: list<array<string, mixed>>, sidecars: list<array<string, mixed>>, partials: list<array<string, mixed>>, titles: list<string>, lookups: int, bytes: int, recent: int}
     */
    public function scan(int $graceDays = 1): array
    {
        // Without the queue table we cannot tell which conversion is being written right now,
        // so fall back to the age guard alone: nothing touched within a day is removed.
        try {
            DB::table('jobs')->exists();
        } catch (Throwable) {
            $graceDays = max($graceDays, 1);
        }

        $cutoff = now()->subDays($graceDays)->timestamp;
        $recent = 0;

        $hls = $this->orphanHls($cutoff, $recent);
        $sidecars = $this->orphanSidecars($cutoff, $recent);
        $partials = $this->stalePartials();

        return [
            'hls' => $hls,
            'sidecars' => $sidecars,
            'partials' => $partials,
            'titles' => $this->orphanTitles(),
            'lookups' => $this->orphanLookupCount(),
            'bytes' => array_sum(array_column($hls, 'bytes')) + array_sum(array_column($sidecars, 'bytes')) + array_sum(array_column($partials, 'bytes')),
            'recent' => $recent,
        ];
    }

    /**
     * Remove what scan() found. Returns the same shape with what was actually removed.
     *
     * @return array{hls: int, sidecars: int, partials: int, titles: int, lookups: int, bytes: int}
     */
    public function prune(int $graceDays = 1): array
    {
        $found = $this->scan($graceDays);
        $videos = Storage::disk('videos');
        $hlsDisk = Storage::disk('hls');
        $removed = ['hls' => 0, 'sidecars' => 0, 'partials' => 0, 'titles' => 0, 'lookups' => 0, 'bytes' => 0];

        foreach ($found['hls'] as $folder) {
            $hlsDisk->deleteDirectory($folder['key']);
            $removed['hls']++;
            $removed['bytes'] += $folder['bytes'];
        }

        foreach ([['sidecars', $found['sidecars']], ['partials', $found['partials']]] as [$kind, $files]) {
            foreach ($files as $file) {
                $videos->delete($file['path']);
                $removed[$kind]++;
                $removed['bytes'] += $file['bytes'];
            }
        }

        try {
            if ($found['titles'] !== []) {
                $removed['titles'] = Title::whereIn('folder', $found['titles'])->delete();
            }
            $removed['lookups'] = $this->deleteOrphanLookups();
        } catch (Throwable $e) {
            report($e); // database bookkeeping must not undo the file cleanup that already happened
        }

        return $removed;
    }

    /**
     * HLS segment folders that no published playlist references.
     *
     * @return list<array{key: string, bytes: int, age_days: int}>
     */
    private function orphanHls(int $cutoff, int &$recent): array
    {
        $hls = Storage::disk('hls');
        $referenced = $this->referencedKeys() + $this->keysBeingBuilt();
        $found = [];

        foreach ($hls->directories() as $dir) {
            $key = basename($dir);

            // Only folders named like ours are ever candidates; anything else is left alone.
            if (! preg_match('/^[0-9a-f]{16}$/', $key) || isset($referenced[$key])) {
                continue;
            }

            $files = $hls->files($key);
            $newest = $files === [] ? 0 : max(array_map(fn ($f) => $hls->lastModified($f), $files));

            if ($newest > $cutoff) {
                $recent++;

                continue;
            }

            $found[] = [
                'key' => $key,
                'bytes' => array_sum(array_map(fn ($f) => $hls->size($f), $files)),
                'age_days' => $newest ? (int) floor((now()->timestamp - $newest) / 86400) : 0,
            ];
        }

        return $found;
    }

    /**
     * Subtitle files ("<video>.vtt", "<video>.<lang>.vtt|srt") whose video no longer exists in the same folder.
     *
     * @return list<array{path: string, bytes: int}>
     */
    private function orphanSidecars(int $cutoff, int &$recent): array
    {
        $disk = Storage::disk('videos');
        $files = $disk->allFiles();
        $videoStems = [];

        foreach ($files as $file) {
            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), VideoLibrary::VIDEO_EXTENSIONS, true)) {
                $videoStems[dirname($file).'/'.pathinfo($file, PATHINFO_FILENAME)] = true;
            }
        }

        $found = [];
        foreach ($files as $file) {
            if (! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), VideoLibrary::SUBTITLE_EXTENSIONS, true)) {
                continue;
            }

            $dir = dirname($file);
            $name = pathinfo($file, PATHINFO_FILENAME);                                  // "Show.S01E01.fa"
            $withoutLanguage = preg_replace('/\.[a-z]{2,3}$/i', '', $name);              // "Show.S01E01"

            if (isset($videoStems[$dir.'/'.$name]) || isset($videoStems[$dir.'/'.$withoutLanguage])) {
                continue;
            }

            if ($disk->lastModified($file) > $cutoff) {
                $recent++;

                continue;
            }

            $found[] = ['path' => $file, 'bytes' => $disk->size($file)];
        }

        return $found;
    }

    /**
     * Half-written files from a download or conversion that died. Never fresher than a day: a job may still own them.
     *
     * @return list<array{path: string, bytes: int}>
     */
    private function stalePartials(): array
    {
        $disk = Storage::disk('videos');
        $cutoff = now()->subDays(self::PARTIAL_MIN_AGE_DAYS)->timestamp;
        $found = [];

        foreach ($disk->allFiles() as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'part' && $disk->lastModified($file) < $cutoff) {
                $found[] = ['path' => $file, 'bytes' => $disk->size($file)];
            }
        }

        return $found;
    }

    /**
     * @return list<string> top-level folders that have a title record but no longer exist
     */
    private function orphanTitles(): array
    {
        try {
            $disk = Storage::disk('videos');

            return array_values(array_filter(Title::pluck('folder')->all(), fn ($folder) => ! $disk->directoryExists($folder)));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function orphanLookupCount(): int
    {
        try {
            return count($this->orphanLookupIds());
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function deleteOrphanLookups(): int
    {
        $ids = $this->orphanLookupIds();

        foreach (array_chunk($ids, 500) as $chunk) {
            SubtitleLookup::whereIn('id', $chunk)->delete();
        }

        return count($ids);
    }

    /**
     * A lookup record is orphaned when its video, and any converted copy of it, is gone.
     *
     * @return list<int>
     */
    private function orphanLookupIds(): array
    {
        $disk = Storage::disk('videos');
        $stems = [];

        foreach ($disk->allFiles() as $file) {
            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), VideoLibrary::VIDEO_EXTENSIONS, true)) {
                $stems[dirname($file).'/'.pathinfo($file, PATHINFO_FILENAME)] = true;
            }
        }

        return SubtitleLookup::get(['id', 'video_path'])
            ->filter(fn ($row) => ! isset($stems[dirname($row->video_path).'/'.pathinfo($row->video_path, PATHINFO_FILENAME)]))
            ->pluck('id')->all();
    }

    /**
     * @return array<string, true> HLS folders that a playlist in the library points at
     */
    private function referencedKeys(): array
    {
        $videos = Storage::disk('videos');
        $keys = [];

        foreach ($videos->allFiles() as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'm3u8') {
                continue;
            }

            if (($key = MediaProcessor::hlsKeyFromPlaylist((string) $videos->get($file))) !== null) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * HLS folders of conversions a worker is writing right now (their playlist is only published at the end).
     *
     * @return array<string, true>
     */
    private function keysBeingBuilt(): array
    {
        $keys = [];

        try {
            foreach (DB::table('jobs')->whereNotNull('reserved_at')->get(['payload']) as $job) {
                $info = app(QueueMonitor::class)->describe($job->payload);

                if ($info['class'] === 'ProcessVideo' && $info['label'] !== '') {
                    $dir = dirname($info['label']) === '.' ? '' : dirname($info['label']);
                    $keys[MediaProcessor::hlsKey($dir, pathinfo($info['label'], PATHINFO_FILENAME))] = true;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $keys;
    }
}
