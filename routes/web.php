<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\UploadController;

Route::get('/', [VideoController::class, 'index']);
Route::get('/folder/{path?}', [VideoController::class, 'folder'])->where('path', '.*');

// آپلود فایل‌ها
Route::get('/upload', [UploadController::class, 'showUploadForm'])->name('upload.form');
Route::post('/upload', [UploadController::class, 'upload'])->name('upload.video');
Route::post('/create-folder', [UploadController::class, 'createFolder'])->name('create.folder');
