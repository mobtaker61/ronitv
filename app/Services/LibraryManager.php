<?php

namespace App\Services;

use App\Models\SubtitleLookup;
use App\Support\SafePath;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Admin operations on the videos disk. A video is more than one file: it has subtitle sidecars,
 * possibly an HLS segment folder and a subtitle lookup record, and all of them follow the video.
 */
class LibraryManager
{
    private const SIDECAR_EXTENSIONS = ['vtt', 'srt'];

    public function __construct(private TitleCatalog $titles) {}

    /**
     * Folders and files of one directory, with enough detail for a management table.
     * Subtitle sidecars are folded into their video's row.
     *
     * @return array{dirs: list<array<string, mixed>>, files: list<array<string, mixed>>}
     */
    public function entries(string $dir): array
    {
        $disk = Storage::disk('videos');
        $dir = SafePath::clean($dir);

        $dirs = array_map(fn ($d) => [
            'name' => basename($d),
            'path' => $d,
            'items' => count($disk->files($d)) + count($disk->directories($d)),
            'series' => count($disk->directories($d)) > 0,   // seasons inside: a series rather than a single film
        ], $disk->directories($dir));
        usort($dirs, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $files = $disk->files($dir);
        $videos = [];
        $others = [];

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (in_array($extension, VideoLibrary::VIDEO_EXTENSIONS, true)) {
                $videos[] = $file;
            } elseif (! in_array($extension, self::SIDECAR_EXTENSIONS, true)) {
                $others[] = $file;
            }
        }

        $rows = [];
        foreach ($videos as $file) {
            $subs = [];
            foreach ($this->sidecars($file) as $sidecar) {
                $subs[] = preg_match('/\.([a-z]{2,3})\.(?:vtt|srt)$/i', $sidecar, $m) ? strtolower($m[1]) : 'fa';
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $rows[] = [
                'name' => basename($file),
                'path' => $file,
                'kind' => $extension === 'm3u8' ? 'hls' : $extension,
                'size' => $extension === 'm3u8' ? $this->hlsSize($file) : $disk->size($file),
                'subtitles' => array_values(array_unique($subs)),
                'video' => true,
            ];
        }

        foreach ($others as $file) {
            $rows[] = ['name' => basename($file), 'path' => $file, 'kind' => 'file', 'size' => $disk->size($file), 'subtitles' => [], 'video' => false];
        }

        usort($rows, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return ['dirs' => $dirs, 'files' => $rows];
    }

    /**
     * Rename a folder, or a video together with its sidecars (the new name has no extension).
     *
     * @return string the new path
     */
    public function rename(string $path, string $newName): string
    {
        $path = $this->existing($path);
        $newName = SafePath::name($newName);
        $dir = $this->parent($path);

        if (Storage::disk('videos')->directoryExists($path)) {
            return $this->moveDirectory($path, $dir.$newName);
        }

        return $this->moveVideo($path, $dir, $newName);
    }

    /**
     * Move a folder or video (with its sidecars) into another folder ('' is the library root).
     *
     * @return string the new path
     */
    public function move(string $path, string $destination): string
    {
        $path = $this->existing($path);
        $destination = SafePath::clean($destination);
        $disk = Storage::disk('videos');

        if ($destination !== '' && ! $disk->directoryExists($destination)) {
            throw new RuntimeException('Destination folder does not exist.');
        }

        $prefix = $destination === '' ? '' : $destination.'/';

        if ($disk->directoryExists($path)) {
            if ($destination === $path || str_starts_with($destination.'/', $path.'/')) {
                throw new RuntimeException('A folder cannot be moved into itself.');
            }

            return $this->moveDirectory($path, $prefix.basename($path));
        }

        return $this->moveVideo($path, $prefix, pathinfo($path, PATHINFO_FILENAME));
    }

    /**
     * Delete a folder with everything in it, or a video with its sidecars, HLS segments and lookup record.
     */
    public function delete(string $path): void
    {
        $path = $this->existing($path);
        $disk = Storage::disk('videos');

        if ($disk->directoryExists($path)) {
            foreach ($disk->allFiles($path) as $file) {
                $this->deleteHls($file);
            }

            $disk->deleteDirectory($path);

            if (! str_contains($path, '/')) {
                $this->lookups(fn () => $this->titles->forgetFolder($path));
            }
            $this->lookups(fn () => SubtitleLookup::where('video_path', 'like', $this->escapeLike($path).'/%')->delete());

            return;
        }

        foreach ($this->related($path) as $file) {
            $this->deleteHls($file);
            $disk->delete($file);
        }

        $this->lookups(fn () => SubtitleLookup::where('video_path', $path)->delete());
    }

    /**
     * Files that belong to a video: itself, same-name siblings in other formats
     * (an original kept next to its conversion) and "<name>.<lang>.vtt|srt" subtitles.
     *
     * @return list<string>
     */
    public function related(string $path): array
    {
        $disk = Storage::disk('videos');
        $dir = $this->parent($path);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $related = [];

        foreach ($disk->files(rtrim($dir, '/')) as $file) {
            $name = basename($file);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            $sameVideo = pathinfo($name, PATHINFO_FILENAME) === $base && in_array($extension, VideoLibrary::VIDEO_EXTENSIONS, true);
            $sidecar = in_array($extension, self::SIDECAR_EXTENSIONS, true) && str_starts_with($name, $base.'.');

            if ($sameVideo || $sidecar) {
                $related[] = $file;
            }
        }

        return $related;
    }

    /**
     * @return list<string>
     */
    private function sidecars(string $videoPath): array
    {
        return array_values(array_filter(
            $this->related($videoPath),
            fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::SIDECAR_EXTENSIONS, true),
        ));
    }

    private function moveVideo(string $path, string $targetDir, string $newBase): string
    {
        $disk = Storage::disk('videos');
        $oldBase = pathinfo($path, PATHINFO_FILENAME);
        $moves = [];

        foreach ($this->related($path) as $file) {
            $suffix = substr(basename($file), strlen($oldBase)); // ".mkv", ".fa.vtt", ".m3u8"
            $moves[$file] = $targetDir.$newBase.$suffix;
        }

        foreach ($moves as $from => $to) {
            if ($from !== $to && $disk->exists($to)) {
                throw new RuntimeException('A file named '.basename($to).' already exists there.');
            }
        }

        foreach ($moves as $from => $to) {
            if ($from !== $to) {
                $disk->move($from, $to);
            }
        }

        // The HLS segment folder is found through the playlist's own URIs, so it never needs to move.
        $newPath = $targetDir.$newBase.'.'.pathinfo($path, PATHINFO_EXTENSION);
        $this->lookups(fn () => SubtitleLookup::where('video_path', $path)->update(['video_path' => $newPath]));

        return $newPath;
    }

    private function moveDirectory(string $path, string $target): string
    {
        $disk = Storage::disk('videos');

        if ($path !== $target && $disk->exists($target)) {
            throw new RuntimeException('A folder or file named '.basename($target).' already exists there.');
        }

        if ($path !== $target) {
            $disk->move($path, $target);

            // A top-level folder carries its TMDb match along while it stays top-level; otherwise it is no longer a title.
            if (! str_contains($path, '/')) {
                $this->lookups(fn () => str_contains($target, '/') ? $this->titles->forgetFolder($path) : $this->titles->renameFolder($path, $target));
            }

            $this->lookups(fn () => SubtitleLookup::where('video_path', 'like', $this->escapeLike($path).'/%')->each(
                fn (SubtitleLookup $lookup) => $lookup->update(['video_path' => $target.substr($lookup->video_path, strlen($path))]),
            ));
        }

        return $target;
    }

    /**
     * Subtitle lookup records are only a cache of past searches. If the database is unavailable the file
     * operation (already done) must still be reported as done; a stale record just means one repeated search.
     */
    private function lookups(callable $update): void
    {
        try {
            $update();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function deleteHls(string $file): void
    {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'm3u8') {
            return;
        }

        $key = MediaProcessor::hlsKeyFromPlaylist((string) Storage::disk('videos')->get($file));

        if ($key !== null) {
            Storage::disk('hls')->deleteDirectory($key);
        }
    }

    private function hlsSize(string $playlist): int
    {
        $key = MediaProcessor::hlsKeyFromPlaylist((string) Storage::disk('videos')->get($playlist));
        $hls = Storage::disk('hls');

        return $key === null ? 0 : array_sum(array_map(fn ($f) => $hls->size($f), $hls->files($key)));
    }

    /**
     * @return string the cleaned path, which must exist and not be the library root
     */
    private function existing(string $path): string
    {
        $path = SafePath::clean($path);
        $disk = Storage::disk('videos');

        if ($path === '' || (! $disk->exists($path) && ! $disk->directoryExists($path))) {
            throw new RuntimeException('Path does not exist.');
        }

        return $path;
    }

    /**
     * "Show/S1/e1.mkv" -> "Show/S1/", "e1.mkv" -> "".
     */
    private function parent(string $path): string
    {
        return dirname($path) === '.' ? '' : dirname($path).'/';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
