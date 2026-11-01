<?php

namespace App\Http\Controllers;

use App\Support\SafePath;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class UploadController extends Controller
{
    public function showUploadForm()
    {
        $allDirectories = $this->getAllDirectories(Storage::disk('videos'));

        return view('upload', compact('allDirectories'));
    }

    private function getAllDirectories(Filesystem $disk, string $basePath = ''): array
    {
        $directories = [];

        foreach ($disk->directories($basePath) as $dir) {
            $fullPath = $basePath ? $basePath.'/'.basename($dir) : basename($dir);
            $depth = substr_count($fullPath, '/');

            $directories[] = [
                'path' => $fullPath,
                'name' => implode(' / ', explode('/', $fullPath)),
                'level' => $depth,
                'indent' => str_repeat('  ', $depth),
            ];

            $directories = array_merge($directories, $this->getAllDirectories($disk, $fullPath));
        }

        return $directories;
    }

    public function upload(Request $request)
    {
        // max is in kilobytes; keep it within PHP/nginx limits (see config/admin.php)
        $request->validate([
            'video' => ['required', 'file', 'extensions:'.implode(',', VideoController::VIDEO_EXTENSIONS), 'max:'.config('admin.max_upload_kb')],
            'folder' => ['required', 'string'],
            'cover' => ['nullable', 'image', 'extensions:jpg,jpeg', 'max:2048'],
        ]);

        try {
            $folder = SafePath::clean($request->input('folder'));
            $videoFile = $request->file('video');
            $videoName = SafePath::name(basename($videoFile->getClientOriginalName()));
        } catch (InvalidArgumentException) {
            return redirect()->back()->with('error', 'مسیر یا نام فایل نامعتبر است.');
        }

        if ($folder === '') {
            return redirect()->back()->with('error', 'پوشه را انتخاب کنید.');
        }

        try {
            $disk = Storage::disk('videos');

            if ($disk->exists($folder.'/'.$videoName)) {
                return redirect()->back()->with('error', 'فایل با این نام قبلاً وجود دارد!');
            }

            $disk->makeDirectory($folder);
            $disk->putFileAs($folder, $videoFile, $videoName);

            // کاور فقط JPG است چون ویوها دنبال _cover.jpg می‌گردند
            if ($request->hasFile('cover')) {
                $disk->putFileAs($folder, $request->file('cover'), '_cover.jpg');
            }

            return redirect()->back()->with('success', 'فایل با موفقیت آپلود شد!');
        } catch (\Exception $e) {
            report($e);

            return redirect()->back()->with('error', 'خطا در آپلود فایل.');
        }
    }

    public function createFolder(Request $request)
    {
        $request->validate([
            'folder_name' => ['required', 'string', 'max:255'],
            'parent_folder' => ['nullable', 'string'],
        ]);

        try {
            $name = SafePath::name($request->input('folder_name'));
            $parent = SafePath::clean($request->input('parent_folder'));
        } catch (InvalidArgumentException) {
            return redirect()->back()->with('error', 'نام یا مسیر پوشه نامعتبر است.');
        }

        $fullPath = $parent !== '' ? $parent.'/'.$name : $name;

        try {
            $disk = Storage::disk('videos');

            if ($disk->exists($fullPath)) {
                return redirect()->back()->with('error', 'پوشه با این نام قبلاً وجود دارد!');
            }

            $disk->makeDirectory($fullPath);

            return redirect()->back()->with('success', 'پوشه با موفقیت ایجاد شد!');
        } catch (\Exception $e) {
            report($e);

            return redirect()->back()->with('error', 'خطا در ایجاد پوشه.');
        }
    }
}
