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
            'append_to_response' => 'credits,external_ids',
        ]));
    }

    /**
     * Every movie and series TMDb knows under this name, so the admin can pick the right one.
     * Not cached: it is an interactive search, and the chosen result is stored in the database anyway.
     *
     * @param  string|null  $type  movie | tv | null for both
     * @return list<array<string, mixed>>|null null when TMDb could not be reached
     */
    public function searchCandidates(string $query, ?string $type = null, ?int $year = null): ?array
    {
        $results = [];
        $reached = false;

        foreach ($type ? [$type] : ['movie', 'tv'] as $kind) {
            $data = $this->get("search/{$kind}", array_filter([
                'query' => $query,
                'include_adult' => 'false',
                $kind === 'movie' ? 'year' : 'first_air_date_year' => $year,
            ]));

            if ($data === false) {
                continue;
            }

            $reached = true;
            foreach ($data['results'] ?? [] as $item) {
                $date = (string) ($item['release_date'] ?? $item['first_air_date'] ?? '');

                $results[] = [
                    'tmdb_id' => (int) $item['id'],
                    'type' => $kind,
                    'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
                    'original_title' => (string) ($item['original_title'] ?? $item['original_name'] ?? ''),
                    'year' => $date !== '' ? (int) substr($date, 0, 4) : null,
                    'poster_path' => $item['poster_path'] ?? null,
                    'overview' => mb_substr((string) ($item['overview'] ?? ''), 0, 220),
                    'vote_average' => round((float) ($item['vote_average'] ?? 0), 1),
                    'popularity' => (float) ($item['popularity'] ?? 0),
                ];
            }
        }

        if (! $reached) {
            return null;
        }

        // A title released in the requested year comes first; within that, the more popular one.
        usort($results, fn ($a, $b) => [(int) ($b['year'] === $year && $year !== null), $b['popularity']] <=> [(int) ($a['year'] === $year && $year !== null), $a['popularity']]);

        return array_slice($results, 0, 20);
    }

    /**
     * Full TMDb record (credits and external ids included). Falls back to the English overview when the
     * Persian one is empty, which is common for newer or smaller titles.
     *
     * @return array<string, mixed>|null null when TMDb could not be reached or the id does not exist
     */
    public function fetchDetails(int $id, string $type): ?array
    {
        $details = $this->get("{$type}/{$id}", ['append_to_response' => 'credits,external_ids']);

        if ($details === false) {
            return null;
        }

        if (trim((string) ($details['overview'] ?? '')) === '') {
            $english = $this->get("{$type}/{$id}", ['language' => 'en-US']);

            if ($english !== false && trim((string) ($english['overview'] ?? '')) !== '') {
                $details['overview'] = $english['overview'];
                $details['overview_language'] = 'en';
            }
        }

        return $details;
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
