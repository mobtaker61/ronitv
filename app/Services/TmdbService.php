<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TmdbService
{
    protected string $baseUrl = 'https://api.themoviedb.org/3/';

    protected string $imageBaseUrl = 'https://image.tmdb.org/t/p/w500';

    /**
     * جستجوی فیلم یا سریال بر اساس نام (title)
     *
     * @param  string  $type  movie|tv
     */
    public function search(string $query, string $type = 'movie'): ?array
    {
        return $this->cached("search:{$type}:".mb_strtolower($query), function () use ($query, $type) {
            $data = $this->get("search/{$type}", ['query' => $query]);

            return $data === false ? false : ($data['results'][0] ?? null);
        });
    }

    /**
     * دریافت اطلاعات کامل فیلم یا سریال بر اساس TMDb ID
     *
     * @param  string  $type  movie|tv
     */
    public function details(int|string $id, string $type = 'movie'): ?array
    {
        return $this->cached("details:{$type}:{$id}", fn () => $this->get("{$type}/{$id}", [
            'append_to_response' => 'credits',
        ]));
    }

    public function posterUrl(?string $posterPath): ?string
    {
        return $posterPath ? $this->imageBaseUrl.$posterPath : null;
    }

    /**
     * Successful lookups are cached for the configured TTL, empty results for a
     * day (so new titles get retried). Failures are never cached.
     */
    protected function cached(string $key, callable $fetch): ?array
    {
        $key = 'tmdb:'.md5($key);

        if (Cache::has($key)) {
            return Cache::get($key) ?: null;
        }

        $result = $fetch();

        if ($result === false) {
            return null;
        }

        Cache::put($key, $result ?? [], $result ? config('services.tmdb.cache_ttl') : 86400);

        return $result;
    }

    /**
     * @return array|false decoded body, or false when the API is unavailable
     */
    protected function get(string $path, array $query = []): array|false
    {
        $apiKey = config('services.tmdb.key');

        if (empty($apiKey)) {
            return false;
        }

        try {
            $response = Http::timeout(8)->retry(1, 200, throw: false)->get($this->baseUrl.$path, $query + [
                'api_key' => $apiKey,
                'language' => 'fa-IR',
            ]);
        } catch (\Throwable $e) {
            Log::warning('TMDb request failed', ['path' => $path, 'message' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('TMDb error response', ['path' => $path, 'status' => $response->status()]);

            return false;
        }

        return $response->json() ?? false;
    }
}
