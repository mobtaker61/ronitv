<?php

namespace App\Support;

use InvalidArgumentException;

class SubtitleConverter
{
    /**
     * Convert raw .srt or .vtt bytes in any common encoding to UTF-8 WebVTT.
     */
    public static function toVtt(string $raw, string $extension): string
    {
        $extension = strtolower($extension);

        if (! in_array($extension, ['srt', 'vtt'], true)) {
            throw new InvalidArgumentException('Unsupported subtitle format.');
        }

        $text = self::toUtf8($raw);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\{\\\\[^}]*\}/', '', $text); // ASS-style override tags such as {\an8}

        if ($extension === 'vtt' || str_starts_with(ltrim($text), 'WEBVTT')) {
            $text = ltrim($text);

            return str_starts_with($text, 'WEBVTT') ? rtrim($text)."\n" : "WEBVTT\n\n".rtrim($text)."\n";
        }

        // SRT timestamps use a comma for milliseconds, VTT uses a dot.
        $text = preg_replace(
            '/(\d{1,2}:\d{2}:\d{2}),(\d{1,3})\s*-->\s*(\d{1,2}:\d{2}:\d{2}),(\d{1,3})/',
            '$1.$2 --> $3.$4',
            $text,
        );

        if (! preg_match('/-->/', $text)) {
            throw new InvalidArgumentException('No subtitle cues found.');
        }

        return "WEBVTT\n\n".trim($text)."\n";
    }

    /**
     * Strip BOMs and detect the encoding. Persian/Arabic subtitles are often
     * Windows-1256, which is not valid UTF-8, so try that before Latin-1.
     */
    public static function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }

        if (str_starts_with($raw, "\xFF\xFE")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        $arabic = @iconv('CP1256', 'UTF-8//IGNORE', $raw) ?: '';

        // Count Arabic-script letters: a real CP1256 file is mostly Arabic/Persian text.
        $letters = preg_match_all('/\p{L}/u', $arabic);
        $arabicLetters = preg_match_all('/\p{Arabic}/u', $arabic);

        if ($letters > 0 && $arabicLetters / $letters > 0.3) {
            // CP1256 has no Persian ی/ک; files use the Arabic forms, so normalise them.
            return strtr($arabic, ['ي' => 'ی', 'ك' => 'ک']);
        }

        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
