<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use App\Services\TmdbService;

class VideoController extends Controller
{
    public function index()
    {
        $disk = Storage::disk('videos');
        $directories = $disk->directories();

        $films = [];
        $series = [];
        $tmdb = new \App\Services\TmdbService();
        $filmPosters = [];
        $seriesPosters = [];
        foreach ($directories as $dir) {
            $subdirs = $disk->directories($dir);
            $folderName = basename($dir);
            if (count($subdirs) > 0) {
                $series[] = $dir;
                // پوستر سریال از TMDb
                $tmdbInfo = $tmdb->search($folderName, 'tv');
                $seriesPosters[$dir] = $tmdbInfo['poster_path'] ?? null;
            } else {
                $seriesFiles = $disk->files($dir);
                $hasVideo = false;
                foreach ($seriesFiles as $file) {
                    $extension = pathinfo($file, PATHINFO_EXTENSION);
                    if (in_array(strtolower($extension), ['mp4', 'avi', 'mkv', 'mov', 'wmv'])) {
                        $hasVideo = true;
                        break;
                    }
                }
                if ($hasVideo) {
                    $films[] = $dir;
                    // پوستر فیلم از TMDb
                    $tmdbInfo = $tmdb->search($folderName, 'movie');
                    $filmPosters[$dir] = $tmdbInfo['poster_path'] ?? null;
                }
            }
        }

        return view('index', compact('films', 'series', 'filmPosters', 'seriesPosters'));
    }

    public function folder($path = null)
    {
        $disk = Storage::disk('videos');
        $currentPath = $path ?: '';

        // دریافت زیرپوشه‌ها
        $subdirs = [];
        if ($currentPath) {
            $subdirs = $disk->directories($currentPath);
        }

        // دریافت فایل‌ها
        $files = [];
        if ($currentPath) {
            $files = $disk->files($currentPath);
        }

        // فیلتر کردن فایل‌های ویدیو
        $videoFiles = array_filter($files, function($file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            return in_array(strtolower($extension), ['mp4', 'avi', 'mkv', 'mov', 'wmv']);
        });

        // اگر سریال است (دارای زیرپوشه)
        $seasonVideos = [];
        if (count($subdirs) > 0) {
            foreach ($subdirs as $seasonDir) {
                $seasonName = basename($seasonDir);
                $seasonFiles = $disk->files($seasonDir);
                $seasonVideos[$seasonName] = array_filter($seasonFiles, function($file) {
                    $extension = pathinfo($file, PATHINFO_EXTENSION);
                    return in_array(strtolower($extension), ['mp4', 'avi', 'mkv', 'mov', 'wmv']);
                });
            }
        }

        // --- دریافت اطلاعات TMDb فقط برای پوشه اصلی سریال ---
        $tmdb = new TmdbService();
        $tmdbInfo = null;
        $tmdbType = count($subdirs) > 0 ? 'tv' : 'movie';
        $folderName = $currentPath ? basename($currentPath) : null;
        // فقط اگر فصل نیست (یعنی اگر مسیر فعلی خودش فصل نباشد)
        if ($folderName && count($subdirs) > 0) {
            $tmdbInfo = $tmdb->search($folderName, $tmdbType);
            if ($tmdbInfo && isset($tmdbInfo['id'])) {
                $tmdbInfo = $tmdb->details($tmdbInfo['id'], $tmdbType);
            }
        }

        return view('folder', compact('subdirs', 'videoFiles', 'currentPath', 'tmdbInfo', 'tmdbType', 'seasonVideos'));
    }
}
