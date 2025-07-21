<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class VideoController extends Controller
{
    public function index()
    {
        $disk = Storage::disk('videos');
        $directories = $disk->directories();

        $films = [];
        $series = [];
        foreach ($directories as $dir) {
            $subdirs = $disk->directories($dir);
            if (count($subdirs) > 0) {
                $series[] = $dir;
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
                }
            }
        }

        return view('index', compact('films', 'series'));
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

        return view('folder', compact('subdirs', 'videoFiles', 'currentPath'));
    }
}
