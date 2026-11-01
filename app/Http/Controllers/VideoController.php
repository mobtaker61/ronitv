<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AdminBasicAuth;
use App\Services\TmdbService;
use App\Services\VideoLibrary;
use App\Support\SafePath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class VideoController extends Controller
{
    public const VIDEO_EXTENSIONS = VideoLibrary::VIDEO_EXTENSIONS;

    public function __construct(
        protected TmdbService $tmdb,
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

        foreach ($disk->directories() as $dir) {
            $subdirs = $disk->directories($dir);
            $folderName = basename($dir);

            if (count($subdirs) > 0) {
                $series[] = $dir;
                $seriesPosters[$dir] = $this->tmdb->search($folderName, 'tv')['poster_path'] ?? null;
                $seriesSeasonsCount[$dir] = count($subdirs);
            } elseif ($this->library->hasVideo($disk, $dir)) {
                $films[] = $dir;
                $filmPosters[$dir] = $this->tmdb->search($folderName, 'movie')['poster_path'] ?? null;
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

        // اطلاعات TMDb برای پوشه اصلی سریال یا فیلم (پوشه‌ی سطح اول)؛ پوشه‌ی فصل‌ها اطلاعات ندارد
        $tmdbInfo = null;
        $tmdbType = count($subdirs) > 0 ? 'tv' : 'movie';
        $isTopLevel = $currentPath !== '' && ! str_contains($currentPath, '/');
        if ($isTopLevel && ($subdirs !== [] || $videoFiles !== [])) {
            $found = $this->tmdb->search(basename($currentPath), $tmdbType);
            $tmdbInfo = isset($found['id']) ? $this->tmdb->details($found['id'], $tmdbType) : $found;
        }

        $isAdmin = AdminBasicAuth::passes($request);
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
}
