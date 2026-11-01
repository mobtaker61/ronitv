<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadRemoteVideo;
use App\Support\DownloadStatus;
use App\Support\SafePath;
use App\Support\UrlGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DownloadController extends Controller
{
    /**
     * Validate the request and queue the download; the worker does the transfer
     * so large files are not limited by the web request's execution time.
     */
    public function downloadFromUrl(Request $request)
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'folder' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $url = $request->input('url');

        try {
            $folder = SafePath::clean($request->input('folder'));
            $filename = SafePath::name($request->input('filename') ?: basename((string) parse_url($url, PHP_URL_PATH)));
            UrlGuard::resolvePublic($url); // fail fast; the job validates again before connecting
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', 'ورودی نامعتبر است: '.$e->getMessage());
        }

        if ($folder === '') {
            return redirect()->back()->with('error', 'پوشه را انتخاب کنید.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, VideoController::VIDEO_EXTENSIONS, true)) {
            return redirect()->back()->with('error', 'فقط فایل‌های ویدیویی ('.implode(', ', VideoController::VIDEO_EXTENSIONS).') مجاز هستند.');
        }

        $relativePath = $folder.'/'.$filename;

        if (Storage::disk('videos')->exists($relativePath)) {
            return redirect()->back()->with('error', 'فایل با این نام قبلاً وجود دارد!');
        }

        $id = (string) Str::uuid();
        DownloadStatus::create($id, $relativePath);
        DownloadRemoteVideo::dispatch($id, $url, $relativePath);

        return redirect()->back()->with('success', 'دانلود در صف قرار گرفت. پیشرفت را در همین صفحه ببینید.');
    }

    public function status()
    {
        return response()->json(DownloadStatus::recent());
    }
}
