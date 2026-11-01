<?php

namespace App\Http\Controllers;

use App\Services\VideoLibrary;
use App\Support\SafePath;
use App\Support\SubtitleConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SubtitleController extends Controller
{
    /**
     * Serve a sidecar subtitle as UTF-8 WebVTT (SRT files are converted on the fly and cached).
     */
    public function show(string $path)
    {
        $disk = Storage::disk('videos');

        try {
            $path = SafePath::clean($path);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, VideoLibrary::SUBTITLE_EXTENSIONS, true) || ! $disk->exists($path)) {
            abort(404);
        }

        $vtt = Cache::remember('subtitle:'.md5($path.'|'.$disk->lastModified($path)), 86400, function () use ($disk, $path, $ext) {
            try {
                return SubtitleConverter::toVtt($disk->get($path), $ext);
            } catch (InvalidArgumentException) {
                return null;
            }
        });

        abort_if($vtt === null, 404);

        return response($vtt, 200, [
            'Content-Type' => 'text/vtt; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Attach a subtitle to a video: stored as "<video>.<lang>.vtt" next to it (replaces the same language).
     */
    public function store(Request $request, VideoLibrary $library)
    {
        $request->validate([
            'video' => ['required', 'string'],
            'lang' => ['required', 'string', Rule::in(array_keys($library->languages()))],
            'subtitle' => ['required', 'file', 'extensions:'.implode(',', VideoLibrary::SUBTITLE_EXTENSIONS), 'max:2048'],
        ]);

        $disk = Storage::disk('videos');

        try {
            $video = SafePath::clean($request->input('video'));
        } catch (InvalidArgumentException) {
            return back()->with('error', 'مسیر ویدیو نامعتبر است.');
        }

        if (! in_array(strtolower(pathinfo($video, PATHINFO_EXTENSION)), VideoLibrary::VIDEO_EXTENSIONS, true) || ! $disk->exists($video)) {
            return back()->with('error', 'ویدیوی انتخاب‌شده وجود ندارد.');
        }

        $file = $request->file('subtitle');

        try {
            $vtt = SubtitleConverter::toVtt($file->get(), $file->getClientOriginalExtension());
        } catch (InvalidArgumentException) {
            return back()->with('error', 'فایل زیرنویس معتبر نیست.');
        }

        $dir = dirname($video) === '.' ? '' : dirname($video);
        $target = ($dir !== '' ? $dir.'/' : '').pathinfo($video, PATHINFO_FILENAME).'.'.$request->input('lang').'.vtt';

        // Drop an older .srt of the same language so the new .vtt is the one that shows.
        $disk->delete(preg_replace('/\.vtt$/', '.srt', $target));
        $disk->put($target, $vtt);

        return back()->with('success', 'زیرنویس اضافه شد.');
    }

    public function destroy(Request $request)
    {
        $request->validate(['path' => ['required', 'string']]);

        $disk = Storage::disk('videos');

        try {
            $path = SafePath::clean($request->input('path'));
        } catch (InvalidArgumentException) {
            return back()->with('error', 'مسیر نامعتبر است.');
        }

        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), VideoLibrary::SUBTITLE_EXTENSIONS, true) || ! $disk->exists($path)) {
            return back()->with('error', 'زیرنویس پیدا نشد.');
        }

        $disk->delete($path);

        return back()->with('success', 'زیرنویس حذف شد.');
    }
}
