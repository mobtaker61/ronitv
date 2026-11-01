<?php

use App\Http\Controllers\BunnyVodController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [VideoController::class, 'index']);
Route::get('/folder/{path?}', [VideoController::class, 'folder'])->where('path', '.*');
Route::get('/ajax/season-videos/{series}/{season}', [VideoController::class, 'seasonVideos']);

// مدیریت (آپلود، ساخت پوشه، دانلود از لینک) — فقط ادمین
Route::middleware(['admin', 'throttle:60,1'])->group(function () {
    Route::get('/upload', [UploadController::class, 'showUploadForm'])->name('upload.form');
    Route::post('/upload', [UploadController::class, 'upload'])->name('upload.video');
    Route::post('/create-folder', [UploadController::class, 'createFolder'])->name('create.folder');
    Route::post('/download-url', [DownloadController::class, 'downloadFromUrl'])->name('download.url');
    Route::get('/download-status', [DownloadController::class, 'status'])->name('download.status');
});

// Bunny VOD
Route::get('/vod/collection/{collectionId}', [BunnyVodController::class, 'showCollection'])->name('vod.collection.show');
Route::get('/shahrokh', [BunnyVodController::class, 'showShahrokh'])->name('vod.shahrokh');
