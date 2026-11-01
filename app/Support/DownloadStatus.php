<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Progress of queued URL downloads, kept in the cache so the upload page can
 * poll it. Entries expire after a day; only the latest few are listed.
 */
class DownloadStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    private const TTL = 86400;

    private const KEEP = 15;

    public static function create(string $id, string $path): void
    {
        Cache::put(self::key($id), [
            'id' => $id,
            'path' => $path,
            'status' => self::QUEUED,
            'downloaded' => 0,
            'total' => 0,
            'error' => null,
            'updated_at' => now()->timestamp,
        ], self::TTL);

        $ids = array_slice(array_merge([$id], Cache::get('downloads:recent', [])), 0, self::KEEP);
        Cache::put('downloads:recent', $ids, self::TTL);
    }

    public static function update(string $id, array $changes): void
    {
        $current = Cache::get(self::key($id));

        if ($current) {
            Cache::put(self::key($id), $changes + ['updated_at' => now()->timestamp] + $current, self::TTL);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(): array
    {
        return array_values(array_filter(array_map(
            fn ($id) => Cache::get(self::key($id)),
            Cache::get('downloads:recent', []),
        )));
    }

    private static function key(string $id): string
    {
        return 'download:'.$id;
    }
}
