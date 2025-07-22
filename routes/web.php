<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

Route::get('/', [VideoController::class, 'index']);
Route::get('/folder/{path?}', [VideoController::class, 'folder'])->where('path', '.*');

// آپلود فایل‌ها
Route::get('/upload', [UploadController::class, 'showUploadForm'])->name('upload.form');
Route::post('/upload', [UploadController::class, 'upload'])->name('upload.video');
Route::post('/create-folder', [UploadController::class, 'createFolder'])->name('create.folder');

// دانلود فایل از لینک اینترنتی
Route::post('/download-url', [DownloadController::class, 'downloadFromUrl'])->name('download.url');

Route::get('/ajax/season-videos/{series}/{season}', function($series, $season) {
    $disk = Storage::disk('videos');
    $seasonPath = $series . '/' . $season;
    $files = $disk->files($seasonPath);
    $videoFiles = array_filter($files, function($file) {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        return in_array(strtolower($extension), ['mp4', 'avi', 'mkv', 'mov', 'wmv']);
    });
    $result = [];
    foreach ($videoFiles as $file) {
        $fileName = basename($file);
        $result[] = [
            'name' => $fileName,
            'url' => asset('storage/videos/' . $seasonPath . '/' . $fileName),
        ];
    }
    return response()->json($result);
});
