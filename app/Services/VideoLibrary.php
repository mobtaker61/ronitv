<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Reads the videos disk: lists playable files with their sidecar subtitles.
 */
class VideoLibrary
{
    public const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mkv', 'mov', 'wmv'];

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
     * @return list<array{name: string, path: string, url: string, type: string, subtitles: list<array<string, mixed>>}>
     */
    public function videos(Filesystem $disk, string $dir): array
    {
        $files = $disk->files($dir);
        natcasesort($files);

        $videos = array_filter($files, fn ($f) => in_array($this->extension($f), self::VIDEO_EXTENSIONS, true));

        return array_values(array_map(fn ($file) => [
            'name' => basename($file),
            'path' => $file,
            'url' => $this->url($file),
            'type' => $this->mimeFor($file),
            'subtitles' => $this->subtitlesFor($file, $files),
        ], $videos));
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
     */
    private function mimeFor(string $file): string
    {
        return 'video/mp4';
    }
}
