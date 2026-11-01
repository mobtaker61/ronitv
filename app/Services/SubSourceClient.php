<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for https://api.subsource.net (docs: https://subsource.net/api-docs).
 * Authenticates with the X-API-Key header; free keys allow 60 requests/minute.
 */
class SubSourceClient
{
    private const MAX_ZIP_BYTES = 5 * 1024 * 1024;

    public function isConfigured(): bool
    {
        return (string) config('services.subsource.key') !== '';
    }

    /**
     * Find the SubSource entry for a title. Series have one entry per season.
     *
     * @return list<array<string, mixed>>
     */
    public function searchMovies(?string $imdbId, string $title, ?int $year, ?int $season): array
    {
        $query = array_filter([
            'searchType' => $imdbId ? 'imdb' : 'text',
            'imdb' => $imdbId,
            'q' => $imdbId ? null : $title,
            'year' => $imdbId ? null : $year,
            'type' => $season !== null ? 'series' : null,
            'season' => $season,
        ], fn ($v) => $v !== null);

        return $this->get('movies/search', $query)->json('data') ?? [];
    }

    /**
     * Persian subtitles of one movie / season.
     *
     * @return list<array<string, mixed>>
     */
    public function subtitles(int $movieId): array
    {
        return $this->get('subtitles', [
            'movieId' => $movieId,
            'language' => config('services.subsource.language'),
            'limit' => 100,
        ])->json('data') ?? [];
    }

    /**
     * The ZIP archive of one subtitle.
     */
    public function download(int $subtitleId): string
    {
        $body = $this->get("subtitles/{$subtitleId}/download")->body();

        if ($body === '' || strlen($body) > self::MAX_ZIP_BYTES || ! str_starts_with($body, 'PK')) {
            throw new RuntimeException('SubSource returned an unexpected download.');
        }

        return $body;
    }

    private function get(string $path, array $query = []): Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('SUBSOURCE_API_KEY is not set.');
        }

        $response = $this->request()->get(rtrim(config('services.subsource.base_url'), '/').'/'.$path, $query);

        if ($response->status() === 429) {
            // Out of quota: wait the advertised time once (capped), then try again.
            sleep(min(10, max(1, (int) $response->header('Retry-After'))));
            $response = $this->request()->get(rtrim(config('services.subsource.base_url'), '/').'/'.$path, $query);
        }

        if ($response->failed()) {
            throw new RuntimeException("SubSource responded with {$response->status()} for {$path}.");
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => config('services.subsource.key'), 'Accept' => 'application/json'])
            ->timeout(20)
            ->connectTimeout(8);
    }
}
