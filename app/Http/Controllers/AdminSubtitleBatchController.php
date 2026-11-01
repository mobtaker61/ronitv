<?php

namespace App\Http\Controllers;

use App\Services\SubSourceClient;
use App\Services\SubtitleBatch;
use App\Support\SafePath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class AdminSubtitleBatchController extends Controller
{
    public function __construct(private SubtitleBatch $batch, private SubSourceClient $api) {}

    /**
     * Queue the missing Persian subtitles: of every video, or of one top-level folder.
     */
    public function start(Request $request)
    {
        $request->validate(['folder' => ['nullable', 'string'], 'only_confirmed' => ['nullable', 'boolean']]);

        if (! $this->api->isConfigured()) {
            return $this->respond($request, false, 'کلید SubSource تنظیم نشده است (SUBSOURCE_API_KEY).');
        }

        $folder = null;
        if ($request->filled('folder')) {
            try {
                $folder = SafePath::name($request->input('folder'));
            } catch (InvalidArgumentException) {
                abort(404);
            }
            abort_unless(Storage::disk('videos')->directoryExists($folder), 404);
        }

        try {
            $batch = $this->batch->start($folder, $request->boolean('only_confirmed', true));
        } catch (RuntimeException $e) {
            return $this->respond($request, false, $e->getMessage());
        }

        if ($batch['total'] === 0) {
            return $this->respond($request, true, 'ویدیویی که زیرنویس لازم داشته باشد پیدا نشد.');
        }

        return $this->respond($request, true, "جستجوی زیرنویس برای {$batch['total']} ویدیو در صف قرار گرفت (حدود ".ceil($batch['total'] * 3 / 60).' دقیقه).');
    }

    public function status()
    {
        return response()->json($this->batch->status());
    }

    private function respond(Request $request, bool $ok, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message, 'status' => $this->batch->status()], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }
}
