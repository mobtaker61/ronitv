<?php

namespace App\Services;

use App\Jobs\ProcessVideo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Read and manage the database queue: what runs, what waits, what failed, and whether a worker is alive.
 */
class QueueMonitor
{
    public const HEARTBEAT_KEY = 'queue:last_activity';

    /** No worker activity for this long while work is waiting means the worker is probably down. */
    private const STALLED_AFTER = 180;

    private const LIST_LIMIT = 40;

    private const TYPES = [
        'ProcessVideo' => 'تبدیل ویدیو',
        'FetchSubtitle' => 'زیرنویس',
        'DownloadRemoteVideo' => 'دانلود از لینک',
    ];

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = now()->timestamp;

        $running = DB::table('jobs')->whereNotNull('reserved_at')->orderBy('reserved_at')->get();
        $waiting = DB::table('jobs')->whereNull('reserved_at')->orderBy('available_at')->orderBy('id');
        $waitingCount = (clone $waiting)->count();
        $readyNow = (clone $waiting)->where('available_at', '<=', $now)->count();

        $perQueue = [];
        foreach (DB::table('jobs')->selectRaw('queue, COUNT(*) as total, SUM(reserved_at IS NOT NULL) as running')->groupBy('queue')->get() as $row) {
            $perQueue[$row->queue] = ['total' => (int) $row->total, 'running' => (int) $row->running, 'waiting' => (int) $row->total - (int) $row->running];
        }

        $failedTotal = DB::table('failed_jobs')->count();
        $last = Cache::get(self::HEARTBEAT_KEY);

        return [
            'now' => $now,
            'queues' => $perQueue,
            'running' => $running->map(fn ($job) => $this->row($job, $now))->all(),
            'waiting' => (clone $waiting)->limit(self::LIST_LIMIT)->get()->map(fn ($job) => $this->row($job, $now))->all(),
            'waitingTotal' => $waitingCount,
            'readyNow' => $readyNow,
            'failed' => DB::table('failed_jobs')->orderByDesc('id')->limit(self::LIST_LIMIT)->get()->map(fn ($job) => $this->failedRow($job))->all(),
            'failedTotal' => $failedTotal,
            'worker' => [
                'lastActivity' => $last,
                'secondsAgo' => $last ? $now - $last : null,
                'stalled' => $running->isEmpty() && $readyNow > 0 && (! $last || $now - $last > self::STALLED_AFTER),
            ],
        ];
    }

    /**
     * Put failed jobs back on the queue: one by uuid, or all of them.
     */
    public function retry(?string $uuid = null): int
    {
        $count = $uuid ? DB::table('failed_jobs')->where('uuid', $uuid)->count() : DB::table('failed_jobs')->count();

        if ($count > 0) {
            Artisan::call('queue:retry', ['id' => [$uuid ?: 'all']]);
        }

        return $count;
    }

    /**
     * Remove failed jobs from the list: one by uuid, or all of them.
     */
    public function forget(?string $uuid = null): int
    {
        return $uuid
            ? DB::table('failed_jobs')->where('uuid', $uuid)->delete()
            : DB::table('failed_jobs')->delete();
    }

    /**
     * Cancel jobs that have not started: one by id, or everything waiting on a queue. A running job is never touched.
     */
    public function cancel(?int $id = null, ?string $queue = null): int
    {
        $query = DB::table('jobs')->whereNull('reserved_at');

        if ($id !== null) {
            $query->where('id', $id);
        } elseif ($queue !== null) {
            $query->where('queue', $queue);
        } else {
            return 0;
        }

        // Subtitle jobs belong to a batch; once they are cancelled the batch must not stay "running".
        $cancelsSubtitles = (clone $query)->get(['payload'])->contains(fn ($job) => ($this->describe($job->payload)['class'] ?? '') === 'FetchSubtitle');
        $removed = $query->delete();

        if ($cancelsSubtitles && $removed > 0) {
            $batch = app(SubtitleBatch::class)->status();
            if ($batch && ($batch['running'] ?? false)) {
                app(SubtitleBatch::class)->abort($batch['id'], 'دسته لغو شد.');
            }
        }

        return $removed;
    }

    /**
     * Queue a conversion for every video that has no converted copy yet and is not already waiting.
     * (The job itself decides what the file needs; files that are fine are skipped there.)
     */
    public function requeueConversions(bool $deleteOriginal): int
    {
        $disk = Storage::disk('videos');
        $files = $disk->allFiles();
        $names = array_flip($files);
        $queued = $this->queuedPaths();
        $count = 0;

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (! in_array($extension, ['mkv', 'avi', 'mov', 'wmv'], true) || isset($queued[$file])) {
                continue;
            }

            $stem = substr($file, 0, -strlen($extension) - 1);
            if (isset($names[$stem.'.m3u8']) || isset($names[$stem.'.mp4'])) {
                continue;
            }

            ProcessVideo::dispatch($file, $deleteOriginal);
            $count++;
        }

        return $count;
    }

    /**
     * What a queued job is, in words: [class, title, label, detail].
     *
     * @return array{class: string, title: string, label: string, detail: string}
     */
    public function describe(string $payload): array
    {
        $data = json_decode($payload, true) ?: [];
        $class = class_basename((string) ($data['displayName'] ?? ($data['data']['commandName'] ?? 'Job')));
        $properties = [];

        // Our own jobs are stored as serialized objects; read them without instantiating any class.
        if (is_string($data['data']['command'] ?? null)) {
            $object = @unserialize($data['data']['command'], ['allowed_classes' => false]);
            $properties = is_object($object) ? (array) $object : [];
        }

        $label = (string) ($properties['relativePath'] ?? $properties['videoPath'] ?? '');
        $detail = '';

        if ($class === 'DownloadRemoteVideo') {
            $host = parse_url((string) ($properties['url'] ?? ''), PHP_URL_HOST);   // never show the full URL: it may carry tokens
            $detail = $host ? 'از '.$host : '';
        } elseif ($class === 'ProcessVideo') {
            $detail = ! empty($properties['deleteOriginal']) ? 'حذف اصل بعد از تأیید' : 'اصل نگه داشته می‌شود';
        }

        return ['class' => $class, 'title' => self::TYPES[$class] ?? $class, 'label' => $label, 'detail' => $detail];
    }

    /**
     * @return array<string, true> video paths that already have a conversion waiting or running
     */
    private function queuedPaths(): array
    {
        $paths = [];

        foreach (DB::table('jobs')->where('queue', 'media')->get(['payload']) as $job) {
            $info = $this->describe($job->payload);
            if ($info['class'] === 'ProcessVideo' && $info['label'] !== '') {
                $paths[$info['label']] = true;
            }
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $job, int $now): array
    {
        $info = $this->describe($job->payload);
        $startedAt = $job->reserved_at;

        return $info + [
            'id' => (int) $job->id,
            'queue' => $job->queue,
            'attempts' => (int) $job->attempts,
            'running' => $startedAt !== null,
            'runningFor' => $startedAt ? max(0, $now - (int) $startedAt) : null,
            'scheduledIn' => $startedAt === null && $job->available_at > $now ? (int) $job->available_at - $now : null,
            'createdAt' => (int) $job->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The first line of an exception for display: no "in /path/file.php:123" suffixes and no absolute server paths.
     */
    private function withoutServerPaths(string $message): string
    {
        $message = str_replace([base_path().DIRECTORY_SEPARATOR, base_path()], '', $message);
        $message = preg_replace('# in (?:[A-Za-z]:)?[\\/]\S+?:\d+#', '', $message);

        return trim($message);
    }

    private function failedRow(object $job): array
    {
        $message = strtok((string) $job->exception, "\n");

        return $this->describe($job->payload) + [
            'id' => (int) $job->id,
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'failedAt' => $job->failed_at,
            'error' => $this->withoutServerPaths((string) $message),
            'retryable' => ! str_contains((string) $message, 'differs from source'),
        ];
    }
}
