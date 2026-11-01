<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remove what nothing points at any more (HLS folders of deleted videos, orphan subtitles, stale partials, dead records).
// Anything touched within the last day is kept, so a conversion that is still being written is never in danger.
Schedule::command('media:prune --grace=1')->hourly()->withoutOverlapping(30)->runInBackground();
