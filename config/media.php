<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ffmpeg binaries
    |--------------------------------------------------------------------------
    */

    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),

    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),

    // Run conversions at the lowest CPU/IO priority so the site stays responsive (ignored on Windows).
    'low_priority' => (bool) env('MEDIA_LOW_PRIORITY', true),

    // Seconds ffmpeg may run for a single file.
    'timeout' => (int) env('MEDIA_TIMEOUT', 14400),

    // Extra free space (bytes) that must remain after a conversion starts.
    'min_free_bytes' => (int) env('MEDIA_MIN_FREE_BYTES', 1073741824),

];
