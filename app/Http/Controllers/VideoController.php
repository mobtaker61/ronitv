<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AdminAuth;
use App\Services\MediaProcessor;
use App\Services\TitleCatalog;
use App\Services\VideoLibrary;
use App\Support\SafePath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Process\Process;

class VideoController extends Controller
{
    /** Formats accepted by the upload and download-from-link forms. */
    public const VIDEO_EXTENSIONS = VideoLibrary::UPLOAD_EXTENSIONS;

    public function __construct(
        protected TitleCatalog $titles,
        protected VideoLibrary $library,
    ) {}

    public function index()
    {
        $disk = Storage::disk('videos');

        $films = [];
        $series = [];
        $filmPosters = [];
        $seriesPosters = [];
        $seriesSeasonsCount = [];

        $directories = $disk->directories();
        $known = $this->titles->forFolders(array_map('basename', $directories));
        $lookups = 6;

        foreach ($directories as $dir) {
            $subdirs = $disk->directories($dir);
            $folderName = basename($dir);
            $isSeries = count($subdirs) > 0;

            if (! $isSeries && ! $this->library->hasVideo($disk, $dir)) {
                continue;
            }

            // Matched once and stored. Only folders never looked up reach TMDb, a few per page view so a big
            // fresh library fills in over the next visits instead of stalling this one.
            $title = $known[$folderName] ?? null;
            if (! $title?->isMatched()) {
                $title = $this->titles->forFolder($folderName, $isSeries ? 'tv' : 'movie', resolve: $lookups > 0 && $lookups-- > 0);
            }

            if ($isSeries) {
                $series[] = $dir;
                $seriesPosters[$dir] = $title?->poster_path;
                $seriesSeasonsCount[$dir] = count($subdirs);
            } else {
                $films[] = $dir;
                $filmPosters[$dir] = $title?->poster_path;
            }
        }

        return view('index', compact('films', 'series', 'filmPosters', 'seriesPosters', 'seriesSeasonsCount'));
    }

    public function folder(Request $request, ?string $path = null)
    {
        $disk = Storage::disk('videos');

        try {
            $currentPath = SafePath::clean($path);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        if ($currentPath !== '' && ! $disk->directoryExists($currentPath)) {
            abort(404);
        }

        $subdirs = $currentPath ? $disk->directories($currentPath) : [];
        $videoFiles = $currentPath ? $this->library->videos($disk, $currentPath) : [];

        // اگر سریال است (دارای زیرپوشه)
        $seasonVideos = [];
        foreach ($subdirs as $seasonDir) {
            $seasonVideos[basename($seasonDir)] = $this->library->videos($disk, $seasonDir);
        }

        // اطلاعات TMDb از دیتابیس (یک بار واکشی و ذخیره می‌شود)؛ پوشه‌ی فصل‌ها اطلاعات ندارد
        $tmdbInfo = null;
        $tmdbType = count($subdirs) > 0 ? 'tv' : 'movie';
        $isTopLevel = $currentPath !== '' && ! str_contains($currentPath, '/');
        if ($isTopLevel && ($subdirs !== [] || $videoFiles !== [])) {
            $title = $this->titles->forFolder($currentPath, $tmdbType);
            $tmdbInfo = $title?->details;
            $tmdbType = $title?->media_type ?? $tmdbType;
        }

        $isAdmin = AdminAuth::check($request);
        $languages = $this->library->languages();

        return view('folder', compact('subdirs', 'videoFiles', 'currentPath', 'tmdbInfo', 'tmdbType', 'seasonVideos', 'isAdmin', 'languages'));
    }

    /**
     * JSON list of the video files (with subtitles) in one season folder, used by the season tabs.
     */
    public function seasonVideos(string $series, string $season)
    {
        try {
            $seasonPath = SafePath::clean($series.'/'.$season);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return response()->json($this->library->videos(Storage::disk('videos'), $seasonPath));
    }

    /**
     * Download a multi-audio (HLS) video as one MP4 with every audio track, muxed on the fly without re-encoding.
     * Plain files are linked directly and never come through here.
     */
    public function download(string $path)
    {
        try {
            $path = SafePath::clean($path);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $videos = Storage::disk('videos');
        abort_unless(strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'm3u8' && $videos->exists($path), 404);

        $base = pathinfo($path, PATHINFO_FILENAME);
        $key = MediaProcessor::hlsKeyFromPlaylist((string) $videos->get($path));
        abort_if($key === null, 404);

        $master = Storage::disk('hls')->path($key.'/master.m3u8');
        abort_unless(is_file($master), 404);

        $filename = $base.'.mp4';
        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, preg_replace('/[^ -~]/', '_', $filename));

        return response()->stream(function () use ($master) {
            $process = new Process([
                config('media.ffmpeg'), '-v', 'error', '-nostdin', '-i', $master,
                '-map', '0:v:0', '-map', '0:a', '-c', 'copy',
                '-f', 'mp4', '-movflags', 'frag_keyframe+empty_moov+default_base_moof', 'pipe:1',
            ]);
            $process->setTimeout(null);
            $process->start();

            foreach ($process as $type => $data) {
                if ($type === Process::OUT) {
                    echo $data;
                    flush();
                }

                if (connection_aborted()) {
                    $process->stop(1);
                    break;
                }
            }
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => $disposition,
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store',
        ]);
    }
}
