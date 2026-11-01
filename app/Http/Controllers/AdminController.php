<?php

namespace App\Http\Controllers;

use App\Services\LibraryManager;
use App\Services\VideoLibrary;
use App\Support\DownloadStatus;
use App\Support\SafePath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AdminController extends Controller
{
    private const CONVERTIBLE = ['mkv', 'avi', 'mov', 'wmv'];

    public function __construct(private LibraryManager $library) {}

    public function dashboard()
    {
        $videos = Storage::disk('videos');
        $files = $videos->allFiles();
        $names = array_flip($files);

        $stats = ['titles' => count($videos->directories()), 'videos' => 0, 'hls' => 0, 'mp4' => 0, 'waiting' => 0, 'noSubtitle' => 0, 'bytes' => 0];

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (! in_array($extension, VideoLibrary::VIDEO_EXTENSIONS, true)) {
                continue;
            }

            $stem = substr($file, 0, -strlen($extension) - 1);

            // An original kept next to its conversion is not a library entry of its own.
            $hasHls = isset($names[$stem.'.m3u8']);
            $hasMp4 = isset($names[$stem.'.mp4']);
            if (($extension === 'mp4' && $hasHls) || (in_array($extension, self::CONVERTIBLE, true) && ($hasHls || $hasMp4))) {
                continue;
            }

            $stats['videos']++;
            $stats['bytes'] += $extension === 'm3u8' ? 0 : $videos->size($file);
            $stats['hls'] += (int) ($extension === 'm3u8');
            $stats['mp4'] += (int) ($extension === 'mp4');
            $stats['waiting'] += (int) in_array($extension, self::CONVERTIBLE, true);
            $stats['noSubtitle'] += (int) ! (isset($names[$stem.'.fa.vtt']) || isset($names[$stem.'.fa.srt']) || isset($names[$stem.'.vtt']) || isset($names[$stem.'.srt']));
        }

        $hlsDisk = Storage::disk('hls');
        $stats['bytes'] += array_sum(array_map(fn ($f) => $hlsDisk->size($f), $hlsDisk->allFiles()));

        $root = $videos->path('');
        $disk = ['free' => (int) @disk_free_space($root), 'total' => (int) @disk_total_space($root)];

        $queue = ['default' => 0, 'media' => 0, 'failed' => 0, 'recentFailures' => [], 'unavailable' => false];
        try {
            if (Schema::hasTable('jobs')) {
                $queue['default'] = DB::table('jobs')->where('queue', 'default')->count();
                $queue['media'] = DB::table('jobs')->where('queue', 'media')->count();
            }
            if (Schema::hasTable('failed_jobs')) {
                $queue['failed'] = DB::table('failed_jobs')->count();
                $queue['recentFailures'] = DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get(['queue', 'failed_at', 'exception'])
                    ->map(fn ($row) => ['queue' => $row->queue, 'at' => $row->failed_at, 'message' => strtok((string) $row->exception, '
')])->all();
            }
        } catch (Throwable $e) {
            report($e);
            $queue['unavailable'] = true; // the page is still useful without the queue figures
        }

        return view('admin.dashboard', [
            'stats' => $stats,
            'disk' => $disk,
            'queue' => $queue,
            'downloads' => DownloadStatus::recent(),
        ]);
    }

    public function library(Request $request, ?string $path = null)
    {
        try {
            $current = SafePath::clean($path);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($current !== '' && ! Storage::disk('videos')->directoryExists($current), 404);

        return view('admin.library', [
            'current' => $current,
            'entries' => $this->library->entries($current),
            'folders' => $this->allFolders(),
        ]);
    }

    public function rename(Request $request)
    {
        $request->validate(['path' => ['required', 'string'], 'name' => ['required', 'string', 'max:200']]);

        return $this->perform(fn () => $this->library->rename($request->input('path'), $request->input('name')), 'نام تغییر کرد.');
    }

    public function move(Request $request)
    {
        $request->validate(['path' => ['required', 'string'], 'destination' => ['nullable', 'string']]);

        return $this->perform(fn () => $this->library->move($request->input('path'), (string) $request->input('destination')), 'جابه‌جا شد.');
    }

    public function delete(Request $request)
    {
        $request->validate(['path' => ['required', 'string']]);

        return $this->perform(function () use ($request) {
            $this->library->delete($request->input('path'));
        }, 'حذف شد.');
    }

    /**
     * Run a library operation and send the admin back to the folder they were in.
     */
    private function perform(callable $operation, string $success)
    {
        try {
            $operation();
        } catch (InvalidArgumentException) {
            return back()->with('error', 'مسیر یا نام نامعتبر است.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /**
     * Every folder as a path, for the "move to" picker.
     *
     * @return list<string>
     */
    private function allFolders(): array
    {
        $folders = Storage::disk('videos')->allDirectories();
        natcasesort($folders);

        return array_values($folders);
    }
}
