<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BunnyStreamService
{
    protected string $libraryId;

    protected string $accessKey;

    protected string $baseUrl = 'https://video.bunnycdn.com';

    public function __construct()
    {
        $this->libraryId = config('services.bunny.stream_library_id');
        $this->accessKey = config('services.bunny.stream_access_key');
    }

    /**
     * دریافت لیست ویدیوهای یک collection
     */
    public function getCollectionVideos(string $collectionId, int $page = 1, int $itemsPerPage = 100, string $orderBy = 'date'): ?array
    {
        try {
            $response = Http::withHeaders([
                'AccessKey' => $this->accessKey,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/library/{$this->libraryId}/videos", [
                'collection' => $collectionId,
                'page' => $page,
                'itemsPerPage' => $itemsPerPage,
                'orderBy' => $orderBy,
            ]);

            if ($response->failed()) {
                Log::error('Bunny Stream API Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'collection_id' => $collectionId,
                ]);

                return null;
            }

            $data = $response->json();

            return $data['items'] ?? [];

        } catch (\Exception $e) {
            Log::error('Bunny Stream Service Exception', [
                'message' => $e->getMessage(),
                'collection_id' => $collectionId,
            ]);

            return null;
        }
    }

    /**
     * ساخت embed URL برای ویدیو
     */
    public function getEmbedUrl(string $videoGuid, bool $autoplay = false, bool $loop = false, bool $muted = false, bool $preload = true): string
    {
        $params = http_build_query([
            'autoplay' => $autoplay ? 'true' : 'false',
            'loop' => $loop ? 'true' : 'false',
            'muted' => $muted ? 'true' : 'false',
            'preload' => $preload ? 'true' : 'false',
        ]);

        return "https://iframe.mediadelivery.net/embed/{$this->libraryId}/{$videoGuid}?{$params}";
    }

    /**
     * ساخت Direct Play URL برای ویدیو
     */
    public function getDirectPlayUrl(string $videoGuid): string
    {
        // فرمت Direct Play URL در Bunny.net (همان embed URL)
        return "https://player.mediadelivery.net/embed/{$this->libraryId}/{$videoGuid}";
    }

    /**
     * تبدیل ویدیوهای خام به ساختار قابل استفاده
     */
    public function formatVideos(array $videos): array
    {
        return array_map(function ($video) {
            $guid = $video['guid'] ?? '';

            return [
                'title' => $video['title'] ?? 'Untitled',
                'guid' => $guid,
                'embed_url' => $this->getEmbedUrl($guid),
                'direct_play_url' => $this->getDirectPlayUrl($guid),
                'thumbnail' => $video['thumbnailFileName'] ?? null,
                'duration' => $video['length'] ?? 0,
                'views' => $video['views'] ?? 0,
            ];
        }, $videos);
    }
}
