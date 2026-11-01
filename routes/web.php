<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminQueueController;
use App\Http\Controllers\AdminSubtitleBatchController;
use App\Http\Controllers\AdminTitleController;
use App\Http\Controllers\BunnyVodController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\SubtitleController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [VideoController::class, 'index']);
Route::get('/folder/{path?}', [VideoController::class, 'folder'])->where('path', '.*');
Route::get('/ajax/season-videos/{series}/{season}', [VideoController::class, 'seasonVideos']);
Route::get('/download/{path}', [VideoController::class, 'download'])->where('path', '.*')->middleware('throttle:10,1')->name('video.download');
Route::get('/subtitle/{path}', [SubtitleController::class, 'show'])->where('path', '.*')->name('subtitle.show');

// ورود ادمین (session)
Route::get('/admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:20,1')->name('admin.login.submit');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

// مدیریت (داشبورد، کتابخانه، آپلود، ساخت پوشه، دانلود از لینک) — فقط ادمین
Route::middleware(['admin', 'throttle:120,1'])->group(function () {
    Route::get('/admin', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('/admin/library/{path?}', [AdminController::class, 'library'])->where('path', '.*')->name('admin.library');
    Route::post('/admin/rename', [AdminController::class, 'rename'])->name('admin.rename');
    Route::post('/admin/move', [AdminController::class, 'move'])->name('admin.move');
    Route::post('/admin/delete', [AdminController::class, 'delete'])->name('admin.delete');
    Route::get('/admin/titles/search', [AdminTitleController::class, 'search'])->name('admin.titles.search');
    Route::post('/admin/titles/assign', [AdminTitleController::class, 'assign'])->name('admin.titles.assign');
    Route::post('/admin/titles/refresh', [AdminTitleController::class, 'refresh'])->name('admin.titles.refresh');
    Route::post('/admin/titles/reset', [AdminTitleController::class, 'reset'])->name('admin.titles.reset');
    Route::post('/admin/prune', [AdminController::class, 'prune'])->name('admin.prune');
    Route::get('/admin/queue', [AdminQueueController::class, 'index'])->name('admin.queue');
    Route::post('/admin/queue/retry', [AdminQueueController::class, 'retry'])->name('admin.queue.retry');
    Route::post('/admin/queue/forget', [AdminQueueController::class, 'forget'])->name('admin.queue.forget');
    Route::post('/admin/queue/cancel', [AdminQueueController::class, 'cancel'])->name('admin.queue.cancel');
    Route::post('/admin/queue/requeue', [AdminQueueController::class, 'requeue'])->name('admin.queue.requeue');
    Route::post('/admin/subtitles/batch', [AdminSubtitleBatchController::class, 'start'])->name('admin.subtitles.batch');
    Route::get('/admin/subtitles/batch', [AdminSubtitleBatchController::class, 'status'])->name('admin.subtitles.batch.status');

    Route::get('/upload', [UploadController::class, 'showUploadForm'])->name('upload.form');
    Route::post('/upload', [UploadController::class, 'upload'])->name('upload.video');
    Route::post('/create-folder', [UploadController::class, 'createFolder'])->name('create.folder');
    Route::post('/download-url', [DownloadController::class, 'downloadFromUrl'])->name('download.url');
    Route::post('/subtitles', [SubtitleController::class, 'store'])->name('subtitle.store');
    Route::delete('/subtitles', [SubtitleController::class, 'destroy'])->name('subtitle.destroy');
    Route::get('/subtitles/online', [SubtitleController::class, 'online'])->name('subtitle.online');
    Route::post('/subtitles/online', [SubtitleController::class, 'fetchOnline'])->name('subtitle.fetch');
    Route::get('/download-status', [DownloadController::class, 'status'])->name('download.status');
});

// Bunny VOD
Route::get('/vod/collection/{collectionId}', [BunnyVodController::class, 'showCollection'])->name('vod.collection.show');
Route::get('/shahrokh', [BunnyVodController::class, 'showShahrokh'])->name('vod.shahrokh');
