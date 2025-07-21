<?php

namespace App\Services;

use GuzzleHttp\Client;

class TmdbService
{
    protected $client;
    protected $apiKey;
    protected $baseUrl = 'https://api.themoviedb.org/3/';
    protected $imageBaseUrl = 'https://image.tmdb.org/t/p/w500';

    public function __construct()
    {
        $this->client = new Client();
        $this->apiKey = env('TMDB_API_KEY');
    }

    /**
     * جستجوی فیلم یا سریال بر اساس نام (title)
     * @param string $query
     * @param string $type movie|tv
     * @return array|null
     */
    public function search($query, $type = 'movie')
    {
        $response = $this->client->get($this->baseUrl . "search/{$type}", [
            'query' => [
                'api_key' => $this->apiKey,
                'language' => 'fa-IR',
                'query' => $query,
            ]
        ]);
        $data = json_decode($response->getBody(), true);
        return $data['results'][0] ?? null;
    }

    /**
     * دریافت اطلاعات کامل فیلم یا سریال بر اساس TMDb ID
     * @param int $id
     * @param string $type movie|tv
     * @return array|null
     */
    public function details($id, $type = 'movie')
    {
        $response = $this->client->get($this->baseUrl . "{$type}/{$id}", [
            'query' => [
                'api_key' => $this->apiKey,
                'language' => 'fa-IR',
                'append_to_response' => 'credits',
            ]
        ]);
        $data = json_decode($response->getBody(), true);
        return $data;
    }

    /**
     * دریافت URL پوستر
     */
    public function posterUrl($posterPath)
    {
        return $posterPath ? $this->imageBaseUrl . $posterPath : null;
    }
}
