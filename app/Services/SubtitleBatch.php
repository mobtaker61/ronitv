<?php

namespace App\Services;

use App\Jobs\FetchSubtitle;
use App\Models\SubtitleLookup;
use App\Models\Title;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Fetches the missing Persian subtitles of many videos through the queue.
 *
 * One small job per video, spread out in time so the SubSource quota (free keys: 60/min, 1,800/h) is never
 * the limit, and tracked in the cache so the dashboard can show progress.
 */
class SubtitleBatch
{
    /** Seconds between two videos' jobs; each video costs a handful of API calls. */
    private const SPACING = 3;

    /** A batch is "running" until all its jobs reported, or this long after its last report (a dead worker). */
    private const STALE_AFTER = 1800;

    private const MAX_PER_BATCH = 300;

    private const KEY = 'subtitles:batch';

    public function __construct(private SubtitleFinder $finder) {}

    /**
     * Videos that still need a subtitle and are worth asking the service about.
     *
     * @param  string|null  $folder  limit to one top-level folder
     * @param  bool  $onlyConfirmed  only titles the admin confirmed (a wrong name guess would fetch the wrong film's subtitles)
     * @return list<string>
     */
    public function pending(?string $folder = null, bool $onlyConfirmed = true): array
    {
        $disk = Storage::disk('videos');
        $files = $disk->allFiles($folder ?? '');
        $names = array_flip($files);
        $confirmed = $onlyConfirmed ? $this->confirmedFolders() : null;
        $recentMisses = $this->recentMisses();
        $pending = [];

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (! in_array($extension, VideoLibrary::VIDEO_EXTENSIONS, true)) {
                continue;
            }

            // An original kept next to its converted copy is the same video: ask once, for the copy.
            $stem = substr($file, 0, -strlen($extension) - 1);
            if (($extension !== 'm3u8' && isset($names[$stem.'.m3u8'])) || (! in_array($extension, ['m3u8', 'mp4'], true) && isset($names[$stem.'.mp4']))) {
                continue;
            }

            if ($confirmed !== null && ! isset($confirmed[explode('/', $file)[0]])) {
                continue;
            }

            if (isset($recentMisses[$file]) || $this->finder->hasLocal($file)) {
                continue;
            }

            $pending[] = $file;
        }

        natcasesort($pending);

        return array_values($pending);
    }

    /**
     * Queue the lookups. Returns the batch status, or throws when one is already running.
     *
     * @return array<string, mixed>
     */
    public function start(?string $folder = null, bool $onlyConfirmed = true): array
    {
        if ($this->isRunning()) {
            throw new RuntimeException('یک دریافت دسته‌ای در حال اجراست.');
        }

        $videos = array_slice($this->pending($folder, $onlyConfirmed), 0, self::MAX_PER_BATCH);

        $batch = [
            'id' => bin2hex(random_bytes(6)),
            'total' => count($videos),
            'done' => 0, 'stored' => 0, 'none' => 0, 'exists' => 0, 'failed' => 0,
            'scope' => $folder ?? '*',
            'only_confirmed' => $onlyConfirmed,
            'started_at' => now()->timestamp,
            'updated_at' => now()->timestamp,
            'error' => null,
        ];
        $this->save($batch);

        foreach ($videos as $i => $video) {
            FetchSubtitle::dispatch($video, $batch['id'])->delay(now()->addSeconds($i * self::SPACING));
        }

        return $batch;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        $batch = Cache::get(self::KEY);

        return $batch ? $batch + ['running' => $this->isRunning($batch)] : null;
    }

    /**
     * A job reports back. Ignored when it belongs to an older batch.
     *
     * @param  string  $outcome  stored | none | exists | failed
     */
    public function report(string $batchId, string $outcome, ?string $error = null): void
    {
        $batch = Cache::get(self::KEY);

        if (! $batch || $batch['id'] !== $batchId) {
            return;
        }

        $batch['done']++;
        $batch[$outcome] = ($batch[$outcome] ?? 0) + 1;
        $batch['updated_at'] = now()->timestamp;
        $batch['error'] = $error ?? $batch['error'];
        $this->save($batch);
    }

    /**
     * The service refused us (bad key): stop wasting jobs.
     */
    public function abort(string $batchId, string $reason): void
    {
        $batch = Cache::get(self::KEY);

        if ($batch && $batch['id'] === $batchId) {
            $batch['error'] = $reason;
            $batch['aborted'] = true;
            $batch['updated_at'] = now()->timestamp;
            $this->save($batch);
        }
    }

    public function isAborted(string $batchId): bool
    {
        $batch = Cache::get(self::KEY);

        return $batch && $batch['id'] === $batchId && ($batch['aborted'] ?? false);
    }

    /**
     * @param  array<string, mixed>|null  $batch
     */
    public function isRunning(?array $batch = null): bool
    {
        $batch ??= Cache::get(self::KEY);

        return $batch
            && empty($batch['aborted'])
            && $batch['done'] < $batch['total']
            && now()->timestamp - $batch['updated_at'] < self::STALE_AFTER + $batch['total'] * self::SPACING;
    }

    /**
     * @return array<string, true> folder names the admin explicitly linked to a TMDb title
     */
    private function confirmedFolders(): array
    {
        try {
            return array_fill_keys(Title::where('matched_by', Title::MANUAL)->whereNotNull('tmdb_id')->pluck('folder')->all(), true);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @return array<string, true> videos already searched without success within the miss window
     */
    private function recentMisses(): array
    {
        try {
            return array_fill_keys(
                SubtitleLookup::where('status', SubtitleLookup::NONE)
                    ->where('checked_at', '>', now()->subDays(config('services.subsource.miss_days')))
                    ->pluck('video_path')->all(),
                true,
            );
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $batch
     */
    private function save(array $batch): void
    {
        Cache::put(self::KEY, $batch, 86400);
    }
}
