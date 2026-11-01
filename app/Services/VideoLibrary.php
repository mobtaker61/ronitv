<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Reads the videos disk: lists playable files with their sidecar subtitles.
 */
class VideoLibrary
{
    /** Formats an admin may upload or download from a link. */
    public const UPLOAD_EXTENSIONS = ['mp4', 'avi', 'mkv', 'mov', 'wmv'];

    /** Everything the player can list: the above plus HLS playlists made by the media pipeline. */
    public const VIDEO_EXTENSIONS = [...self::UPLOAD_EXTENSIONS, 'm3u8'];

    public const SUBTITLE_EXTENSIONS = ['vtt', 'srt'];

    private const LANGUAGES = [
        'fa' => 'فارسی',
        'en' => 'English',
        'ar' => 'العربية',
        'tr' => 'Türkçe',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'es' => 'Español',
        'ru' => 'Русский',
    ];

    /**
     * Video files of one directory, naturally sorted, each with its subtitles.
     *
     * @return list<array{name: string, path: string, url: string, type: string, download: string, subtitles: list<array<string, mixed>>}>
     */
    public function videos(Filesystem $disk, string $dir): array
    {
        $files = $disk->files($dir);
        natcasesort($files);

        $videos = array_filter($files, fn ($f) => in_array($this->extension($f), self::VIDEO_EXTENSIONS, true));
        $videos = $this->withoutSupersededOriginals($videos);

        return array_values(array_map(fn ($file) => [
            'name' => basename($file),
            'path' => $file,
            'url' => $this->url($file),
            'type' => $this->mimeFor($file),
            'download' => $this->extension($file) === 'm3u8' ? route('video.download', ['path' => $file]) : $this->url($file),
            'subtitles' => $this->subtitlesFor($file, $files),
        ], $videos));
    }

    /**
     * When the media pipeline converted a video and kept the original, only the converted copy
     * is listed: the HLS playlist wins over an MP4, and an MP4 wins over MKV/AVI/etc.
     *
     * @param  array<int|string, string>  $videos
     * @return array<int|string, string>
     */
    private function withoutSupersededOriginals(array $videos): array
    {
        $rank = fn (string $file) => match ($this->extension($file)) {
            'm3u8' => 3,
            'mp4' => 2,
            default => 1,
        };

        $best = [];
        foreach ($videos as $file) {
            $key = dirname($file).'/'.pathinfo($file, PATHINFO_FILENAME);
            if (! isset($best[$key]) || $rank($file) > $rank($best[$key])) {
                $best[$key] = $file;
            }
        }

        return array_filter($videos, fn ($file) => in_array($file, $best, true));
    }

    public function hasVideo(Filesystem $disk, string $dir): bool
    {
        foreach ($disk->files($dir) as $file) {
            if (in_array($this->extension($file), self::VIDEO_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Subtitles named "<video>.<ext>" or "<video>.<lang>.<ext>"; a .vtt wins over a .srt for the same language.
     *
     * @param  list<string>  $siblings
     * @return list<array<string, mixed>>
     */
    public function subtitlesFor(string $videoPath, array $siblings): array
    {
        $base = pathinfo($videoPath, PATHINFO_FILENAME);
        $found = [];

        foreach ($siblings as $file) {
            $ext = $this->extension($file);
            if (! in_array($ext, self::SUBTITLE_EXTENSIONS, true)) {
                continue;
            }

            if (! preg_match('/^'.preg_quote($base, '/').'(?:\.([a-z]{2,3}))?\.(?:vtt|srt)$/i', basename($file), $m)) {
                continue;
            }

            $lang = strtolower($m[1] ?? '') ?: 'fa';
            if (isset($found[$lang]) && $ext !== 'vtt') {
                continue;
            }

            $found[$lang] = [
                'lang' => $lang,
                'label' => self::LANGUAGES[$lang] ?? strtoupper($lang),
                'path' => $file,
                'url' => route('subtitle.show', ['path' => $file]),
            ];
        }

        ksort($found);

        return array_values($found);
    }

    public function languages(): array
    {
        return self::LANGUAGES;
    }

    public function url(string $path): string
    {
        return asset('storage/videos/'.implode('/', array_map('rawurlencode', explode('/', $path))));
    }

    private function extension(string $file): string
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION));
    }

    /**
     * Browsers sniff the container, so MKV/AVI are offered as mp4 and play where the codecs allow.
     * HLS playlists (multi-audio videos made by the media pipeline) are played through Video.js' HLS engine.
     */
    private function mimeFor(string $file): string
    {
        return $this->extension($file) === 'm3u8' ? 'application/x-mpegURL' : 'video/mp4';
    }
}
