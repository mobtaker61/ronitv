<?php

namespace App\Http\Controllers;

use App\Services\TmdbService;
use App\Support\SafePath;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class VideoController extends Controller
{
    public const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mkv', 'mov', 'wmv'];

    public function __construct(protected TmdbService $tmdb) {}

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
            } elseif ($this->videoFiles($disk, $dir) !== []) {
                $films[] = $dir;
                $filmPosters[$dir] = $this->tmdb->search($folderName, 'movie')['poster_path'] ?? null;
            }
        }

        return view('index', compact('films', 'series', 'filmPosters', 'seriesPosters', 'seriesSeasonsCount'));
    }

    public function folder(?string $path = null)
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
        $videoFiles = $currentPath ? $this->videoFiles($disk, $currentPath) : [];

        // اگر سریال است (دارای زیرپوشه)
        $seasonVideos = [];
        foreach ($subdirs as $seasonDir) {
            $seasonVideos[basename($seasonDir)] = $this->videoFiles($disk, $seasonDir);
        }

        // اطلاعات TMDb فقط برای پوشه اصلی سریال
        $tmdbInfo = null;
        $tmdbType = count($subdirs) > 0 ? 'tv' : 'movie';
        if ($currentPath && count($subdirs) > 0) {
            $found = $this->tmdb->search(basename($currentPath), $tmdbType);
            $tmdbInfo = isset($found['id']) ? $this->tmdb->details($found['id'], $tmdbType) : $found;
        }

        return view('folder', compact('subdirs', 'videoFiles', 'currentPath', 'tmdbInfo', 'tmdbType', 'seasonVideos'));
    }

    /**
     * JSON list of the video files in one season folder (used by the season tabs).
     */
    public function seasonVideos(string $series, string $season)
    {
        try {
            $seasonPath = SafePath::clean($series.'/'.$season);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $result = array_map(fn ($file) => [
            'name' => basename($file),
            'url' => asset('storage/videos/'.$seasonPath.'/'.basename($file)),
        ], $this->videoFiles(Storage::disk('videos'), $seasonPath));

        return response()->json(array_values($result));
    }

    /**
     * @return list<string>
     */
    protected function videoFiles(Filesystem $disk, string $dir): array
    {
        return array_values(array_filter(
            $disk->files($dir),
            fn ($file) => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true),
        ));
    }
}
