<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DownloadController extends Controller
{
    public function downloadFromUrl(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
            'folder' => 'required|string',
            'filename' => 'nullable|string|max:255'
        ]);

        try {
            $disk = Storage::disk('videos');
            $folder = $request->input('folder');
            $url = $request->input('url');
            $customFilename = $request->input('filename');

            // ایجاد پوشه اگر وجود نداشته باشد
            if (!$disk->exists($folder)) {
                $disk->makeDirectory($folder);
            }

            // دریافت اطلاعات فایل از URL
            $response = Http::timeout(30)->head($url);

            if (!$response->successful()) {
                return redirect()->back()->with('error', 'خطا در دسترسی به فایل: ' . $response->status());
            }

            // تعیین نام فایل
            $filename = $customFilename;
            if (!$filename) {
                // استخراج نام فایل از URL
                $path = parse_url($url, PHP_URL_PATH);
                $filename = basename($path);

                // اگر نام فایل خالی باشد، نام تصادفی ایجاد کن
                if (empty($filename) || $filename === '/') {
                    $filename = 'downloaded_file_' . time() . '.mp4';
                }
            }

            // اضافه کردن پسوند اگر وجود نداشته باشد
            if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                $filename .= '.mp4';
            }

            $filePath = $folder . '/' . $filename;

            // بررسی وجود فایل
            if ($disk->exists($filePath)) {
                return redirect()->back()->with('error', 'فایل با این نام قبلاً وجود دارد!');
            }

            // دانلود فایل
            $response = Http::timeout(300)->get($url);

            if (!$response->successful()) {
                return redirect()->back()->with('error', 'خطا در دانلود فایل: ' . $response->status());
            }

            // ذخیره فایل
            $disk->put($filePath, $response->body());

            return redirect()->back()->with('success', 'فایل با موفقیت دانلود و ذخیره شد!');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'خطا در دانلود فایل: ' . $e->getMessage());
        }
    }
}
