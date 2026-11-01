<?php

namespace App\Providers;

use App\Services\QueueMonitor;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The admin queue page shows whether a worker is alive; the cache is the only place a worker can say so.
        Event::listen([Looping::class, JobProcessing::class, JobProcessed::class, JobFailed::class], function () {
            Cache::put(QueueMonitor::HEARTBEAT_KEY, now()->timestamp, 86400);
        });
    }
}
