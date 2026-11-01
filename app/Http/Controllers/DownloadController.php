<?php

namespace App\Http\Controllers;

use App\Support\SafePath;
use App\Support\UrlGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class DownloadController extends Controller
{
    private const MAX_REDIRECTS = 3;

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
        } catch (InvalidArgumentException) {
            return redirect()->back()->with('error', 'مسیر یا نام فایل نامعتبر است.');
        }

        if ($folder === '') {
            return redirect()->back()->with('error', 'پوشه را انتخاب کنید.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, VideoController::VIDEO_EXTENSIONS, true)) {
            return redirect()->back()->with('error', 'فقط فایل‌های ویدیویی ('.implode(', ', VideoController::VIDEO_EXTENSIONS).') مجاز هستند.');
        }

        $disk = Storage::disk('videos');
        $relativePath = $folder.'/'.$filename;

        if ($disk->exists($relativePath)) {
            return redirect()->back()->with('error', 'فایل با این نام قبلاً وجود دارد!');
        }

        $disk->makeDirectory($folder);
        $target = $disk->path($relativePath);
        $partial = $target.'.part';

        try {
            $this->fetchTo($url, $partial);
            rename($partial, $target);

            return redirect()->back()->with('success', 'فایل با موفقیت دانلود و ذخیره شد!');
        } catch (InvalidArgumentException $e) {
            @unlink($partial);

            return redirect()->back()->with('error', 'آدرس مجاز نیست: '.$e->getMessage());
        } catch (\Throwable $e) {
            @unlink($partial);
            report($e);

            return redirect()->back()->with('error', 'خطا در دانلود فایل.');
        }
    }

    /**
     * Stream the remote file to disk. Every hop (including redirects) is
     * re-validated and pinned to the resolved public IP, and the transfer is
     * aborted once it exceeds the configured size limit.
     */
    private function fetchTo(string $url, string $destination): void
    {
        $maxBytes = (int) config('admin.max_download_kb') * 1024;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = UrlGuard::resolvePublic($url);

            /** @var Response $response */
            $response = Http::timeout(110)
                ->connectTimeout(10)
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $destination,
                    'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]],
                    'progress' => function ($total, $downloaded) use ($maxBytes) {
                        if ($downloaded > $maxBytes) {
                            throw new RuntimeException('File exceeds the download size limit.');
                        }
                    },
                ])
                ->get($url);

            if ($response->redirect()) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new RuntimeException('Redirect without Location header.');
                }
                $url = $this->absoluteUrl($url, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('Remote server responded with '.$response->status());
            }

            return;
        }

        throw new RuntimeException('Too many redirects.');
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_starts_with($location, '/')
            ? $origin.$location
            : $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }
}
