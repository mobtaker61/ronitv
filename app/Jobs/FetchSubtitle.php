<?php

namespace App\Jobs;

use App\Exceptions\SubtitleTitleNotFound;
use App\Services\SubtitleBatch;
use App\Services\SubtitleFinder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * Looks up and stores the Persian subtitle of one video; part of a SubtitleBatch.
 */
class FetchSubtitle implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** A rate-limited (429) job is put back a couple of times before it is given up. */
    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $videoPath,
        public string $batchId,
    ) {}

    public function handle(SubtitleFinder $finder, SubtitleBatch $batch): void
    {
        if ($batch->isAborted($this->batchId)) {
            return;
        }

        try {
            $result = $finder->fetch($this->videoPath);
        } catch (SubtitleTitleNotFound) {
            $batch->report($this->batchId, 'none');

            return;
        } catch (FilesystemException $e) {
            report($e);
            $batch->report($this->batchId, 'failed', 'نوشتن زیرنویس ممکن نشد (دسترسی پوشه‌ی '.dirname($this->videoPath).').');

            return;
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), ' 401 ') || str_contains($e->getMessage(), ' 403 ')) {
                // the key is wrong or revoked: every other job would fail the same way
                $batch->abort($this->batchId, 'کلید SubSource پذیرفته نشد.');

                return;
            }

            if (str_contains($e->getMessage(), ' 429 ') && $this->attempts() < $this->tries) {
                $this->release(120 * $this->attempts()); // out of quota: wait and try again

                return;
            }

            report($e);
            $batch->report($this->batchId, 'failed', str_contains($e->getMessage(), ' 429 ') ? 'سقف درخواست SubSource پر شد.' : null);

            return;
        }

        $batch->report($this->batchId, $result['status'] === 'stored' ? 'stored' : ($result['status'] === 'exists' ? 'exists' : 'none'));
    }
}
