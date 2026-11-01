<?php

namespace App\Jobs;

use App\Services\RemoteDownloader;
use App\Support\DownloadStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class DownloadRemoteVideo implements ShouldQueue
{
    use Queueable;

    /** Large files can take hours; the worker's own --timeout must be at least this. */
    public int $timeout = 21600;

    public int $tries = 1;

    public function __construct(
        public string $id,
        public string $url,
        public string $relativePath,
    ) {}

    public function handle(RemoteDownloader $downloader): void
    {
        $disk = Storage::disk('videos');

        if ($disk->exists($this->relativePath)) {
            $this->failed(new InvalidArgumentException('فایل با این نام قبلاً وجود دارد.'));

            return;
        }

        $disk->makeDirectory(dirname($this->relativePath));
        $target = $disk->path($this->relativePath);
        $partial = $target.'.part';
        $lastReport = 0;

        DownloadStatus::update($this->id, ['status' => DownloadStatus::RUNNING]);

        try {
            $downloader->download($this->url, $partial, function (int $downloaded, int $total) use (&$lastReport) {
                // progress fires very often; write to the cache at most every 2 seconds
                if (time() - $lastReport >= 2) {
                    $lastReport = time();
                    DownloadStatus::update($this->id, ['downloaded' => $downloaded, 'total' => $total]);
                }
            });

            rename($partial, $target);
            $size = filesize($target);
            DownloadStatus::update($this->id, ['status' => DownloadStatus::DONE, 'downloaded' => $size, 'total' => $size]);
        } catch (Throwable $e) {
            @unlink($partial);
            report($e);
            $this->failed($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'دانلود ناموفق بود.';

        DownloadStatus::update($this->id, ['status' => DownloadStatus::FAILED, 'error' => $message]);
    }
}
