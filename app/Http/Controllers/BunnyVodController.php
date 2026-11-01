<?php

namespace App\Http\Controllers;

use App\Services\BunnyStreamService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class BunnyVodController extends Controller
{
    protected BunnyStreamService $bunnyService;

    public function __construct(BunnyStreamService $bunnyService)
    {
        $this->bunnyService = $bunnyService;
    }

    /**
     * نمایش collection ویدیوها
     *
     * @return View|Response
     */
    public function showCollection(string $collectionId)
    {
        // بررسی تنظیمات
        $libraryId = config('services.bunny.stream_library_id');
        $accessKey = config('services.bunny.stream_access_key');

        if (empty($libraryId) || empty($accessKey)) {
            abort(500, 'Bunny Stream configuration is missing. Please check your .env file.');
        }

        // دریافت ویدیوها از API
        $videos = $this->bunnyService->getCollectionVideos($collectionId);

        if ($videos === null) {
            abort(500, 'Failed to fetch videos from Bunny Stream API. Please try again later.');
        }

        // فرمت کردن ویدیوها
        $playlist = $this->bunnyService->formatVideos($videos);

        // اگر playlist خالی بود
        if (empty($playlist)) {
            return view('vod.collection', [
                'playlist' => [],
                'collectionId' => $collectionId,
                'hasVideos' => false,
            ]);
        }

        return view('vod.collection', [
            'playlist' => $playlist,
            'collectionId' => $collectionId,
            'hasVideos' => true,
        ]);
    }

    /**
     * نمایش صفحه shahrokh (redirect به collection مشخص)
     *
     * @return RedirectResponse
     */
    public function showShahrokh()
    {
        // می‌توانید collectionId را از env یا config بگیرید
        // یا مستقیماً به یک collection مشخص redirect کنید
        $collectionId = config('services.bunny.shahrokh_collection_id');

        return redirect()->route('vod.collection.show', ['collectionId' => $collectionId]);
    }
}
