<?php

namespace App\Jobs;

use App\Services\MediaProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessVideo implements ShouldQueue
{
    use Queueable;

    /** Conversions can take a long time on big files; the worker --timeout must be at least this. */
    public int $timeout = 14400;

    public int $tries = 1;

    public function __construct(
        public string $relativePath,
        public bool $deleteOriginal = false,
    ) {
        $this->onQueue('media');
    }

    public function handle(MediaProcessor $processor): void
    {
        $result = $processor->process($this->relativePath, $this->deleteOriginal);

        Log::info('Video processed', ['path' => $this->relativePath] + $result);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Video processing failed', ['path' => $this->relativePath, 'error' => $exception?->getMessage()]);
    }
}
