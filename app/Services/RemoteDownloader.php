<?php

namespace App\Services;

use App\Support\UrlGuard;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteDownloader
{
    private const MAX_REDIRECTS = 3;

    /**
     * Stream a remote file to $destination. Every hop (including redirects) is
     * re-validated and pinned to the resolved public IP, and the transfer is
     * aborted once it exceeds the configured size limit.
     *
     * @param  (callable(int $downloaded, int $total): void)|null  $onProgress
     */
    public function download(string $url, string $destination, ?callable $onProgress = null): void
    {
        $maxBytes = (int) config('admin.max_download_kb') * 1024;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = UrlGuard::resolvePublic($url);

            $response = Http::timeout(0)
                ->connectTimeout(10)
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $destination,
                    'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]],
                    'progress' => function ($total, $downloaded) use ($maxBytes, $onProgress) {
                        if ($downloaded > $maxBytes) {
                            throw new RuntimeException('File exceeds the download size limit.');
                        }
                        if ($onProgress) {
                            $onProgress((int) $downloaded, (int) $total);
                        }
                    },
                ])
                ->get($url);

            if ($response->redirect()) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new RuntimeException('Redirect without Location header.');
                }
                $url = $this->absoluteUrl($url, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('Remote server responded with '.$response->status());
            }

            return;
        }

        throw new RuntimeException('Too many redirects.');
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_starts_with($location, '/')
            ? $origin.$location
            : $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }
}
