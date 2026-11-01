<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class Ffmpeg
{
    private const LANGUAGES = [
        'per' => 'fa', 'fas' => 'fa', 'fa' => 'fa', 'pes' => 'fa',
        'eng' => 'en', 'en' => 'en',
        'ara' => 'ar', 'ar' => 'ar',
        'tur' => 'tr', 'tr' => 'tr',
        'fre' => 'fr', 'fra' => 'fr', 'fr' => 'fr',
        'ger' => 'de', 'deu' => 'de', 'de' => 'de',
        'spa' => 'es', 'es' => 'es',
        'rus' => 'ru', 'ru' => 'ru',
        'jpn' => 'ja', 'ja' => 'ja',
    ];

    private const TEXT_SUBTITLE_CODECS = ['subrip', 'srt', 'ass', 'ssa', 'mov_text', 'webvtt', 'text'];

    /**
     * Stream layout of a media file.
     *
     * @return array{duration: float, size: int, video: ?array<string, mixed>, audio: list<array<string, mixed>>, subtitles: list<array<string, mixed>>}
     */
    public function probe(string $file): array
    {
        $result = Process::timeout(120)->run([
            config('media.ffprobe'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $file,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput()));
        }

        $data = json_decode($result->output(), true) ?: [];
        $video = null;
        $audio = [];
        $subtitles = [];

        foreach ($data['streams'] ?? [] as $stream) {
            $info = [
                'index' => $stream['index'],
                'codec' => $stream['codec_name'] ?? 'unknown',
                'lang' => $this->normaliseLanguage($stream['tags']['language'] ?? null),
                'title' => $stream['tags']['title'] ?? '',
            ];

            match ($stream['codec_type'] ?? '') {
                'video' => $video ??= $info + ['width' => $stream['width'] ?? null, 'height' => $stream['height'] ?? null, 'profile' => $stream['profile'] ?? null, 'level' => (int) ($stream['level'] ?? 0)],
                'audio' => $audio[] = $info + ['channels' => (int) ($stream['channels'] ?? 2)],
                'subtitle' => $subtitles[] = $info + ['text' => in_array($info['codec'], self::TEXT_SUBTITLE_CODECS, true)],
                default => null,
            };
        }

        return [
            'duration' => (float) ($data['format']['duration'] ?? 0),
            'size' => (int) ($data['format']['size'] ?? 0),
            'video' => $video,
            'audio' => $audio,
            'subtitles' => $subtitles,
        ];
    }

    /**
     * Run ffmpeg with the given arguments (after the binary); throws with the tail of stderr on failure.
     *
     * @param  list<string>  $args
     */
    public function run(array $args, ?string $cwd = null): void
    {
        $command = [config('media.ffmpeg'), '-hide_banner', '-nostdin', '-y', '-v', 'error', ...$args];

        if (config('media.low_priority') && PHP_OS_FAMILY !== 'Windows') {
            $command = ['nice', '-n', '19', ...$command];
        }

        $process = Process::timeout(config('media.timeout'));
        if ($cwd !== null) {
            $process = $process->path($cwd);
        }

        $result = $process->run($command);

        if ($result->failed()) {
            throw new RuntimeException('ffmpeg failed: '.substr(trim($result->errorOutput()), -800));
        }
    }

    /**
     * Read every packet of a file (no decoding, no output) and return the distinct warnings ffmpeg printed.
     * Throws when the file cannot be read to the end.
     *
     * @return list<string> warnings with memory addresses removed, so two runs can be compared
     */
    public function readWarnings(string $file, ?string $cwd = null): array
    {
        $command = [config('media.ffmpeg'), '-hide_banner', '-nostdin', '-v', 'warning', '-i', $file, '-map', '0', '-c', 'copy', '-f', 'null', '-'];

        $process = Process::timeout(config('media.timeout'));
        if ($cwd !== null) {
            $process = $process->path($cwd);
        }

        $result = $process->run($command);

        $lines = array_map(
            fn ($line) => trim(preg_replace('/0x[0-9a-f]+/i', '', $line)),
            preg_split('/\R/', trim($result->errorOutput())),
        );

        if ($result->failed()) {
            throw new RuntimeException('Integrity check failed: '.substr(trim($result->errorOutput()), -400));
        }

        return array_values(array_unique(array_filter($lines)));
    }

    /**
     * Two-letter code for an ISO 639 tag, or null for unknown/undetermined.
     */
    public function normaliseLanguage(?string $tag): ?string
    {
        return self::LANGUAGES[strtolower((string) $tag)] ?? null;
    }
}
