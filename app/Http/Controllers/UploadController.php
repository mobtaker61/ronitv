<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class UploadController extends Controller
{
    public function showUploadForm()
    {
        $disk = Storage::disk('videos');
        $directories = $disk->directories();

        return view('upload', compact('directories'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimes:mp4,avi,mkv,mov,wmv|max:2048', // حداکثر 2GB
            'folder' => 'required|string',
            'cover' => 'nullable|image|mimes:jpg,jpeg,png|max:2048'
        ]);

        try {
            $disk = Storage::disk('videos');
            $folder = $request->input('folder');

            // ایجاد پوشه اگر وجود نداشته باشد
            if (!$disk->exists($folder)) {
                $disk->makeDirectory($folder);
            }

            // آپلود فایل ویدیو
            $videoFile = $request->file('video');
            $videoName = $videoFile->getClientOriginalName();
            $videoPath = $folder . '/' . $videoName;
            $disk->putFileAs($folder, $videoFile, $videoName);

            // آپلود کاور اگر انتخاب شده باشد
            if ($request->hasFile('cover')) {
                $coverFile = $request->file('cover');
                $disk->putFileAs($folder, $coverFile, '_cover.jpg');
            }

            return redirect()->back()->with('success', 'فایل با موفقیت آپلود شد!');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'خطا در آپلود فایل: ' . $e->getMessage());
        }
    }

    public function createFolder(Request $request)
    {
        $request->validate([
            'folder_name' => 'required|string|max:255',
            'parent_folder' => 'nullable|string'
        ]);

        try {
            $disk = Storage::disk('videos');
            $folderName = $request->input('folder_name');
            $parentFolder = $request->input('parent_folder');

            $fullPath = $parentFolder ? $parentFolder . '/' . $folderName : $folderName;

            if ($disk->exists($fullPath)) {
                return redirect()->back()->with('error', 'پوشه با این نام قبلاً وجود دارد!');
            }

            $disk->makeDirectory($fullPath);

            return redirect()->back()->with('success', 'پوشه با موفقیت ایجاد شد!');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'خطا در ایجاد پوشه: ' . $e->getMessage());
        }
    }
}
