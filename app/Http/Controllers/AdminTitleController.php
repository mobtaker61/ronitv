<?php

namespace App\Http\Controllers;

use App\Models\Title;
use App\Services\TitleCatalog;
use App\Services\TmdbService;
use App\Support\SafePath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin chooses which TMDb movie/series a library folder is, from every title that shares its name.
 */
class AdminTitleController extends Controller
{
    public function __construct(private TitleCatalog $titles, private TmdbService $tmdb) {}

    /**
     * Every candidate TMDb has for a search (defaults to the folder's own name and a year found in its file names).
     */
    public function search(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200'], 'year' => ['nullable', 'integer', 'between:1880,2100'], 'type' => ['nullable', 'in:movie,tv']]);

        $folder = $this->folder($request);
        $suggest = $this->titles->suggest($folder);
        $query = trim((string) $request->input('q')) ?: $suggest['query'];
        $year = $request->filled('year') ? $request->integer('year') : ($request->filled('q') ? null : $suggest['year']);

        $candidates = $this->tmdb->searchCandidates($query, $request->input('type') ?: null, $year);

        if ($candidates === null) {
            return response()->json(['message' => 'ارتباط با TMDb برقرار نشد (کلید API یا شبکه را بررسی کنید).'], 502);
        }

        $current = Title::where('folder', $folder)->first();

        return response()->json([
            'query' => $query,
            'year' => $year,
            'current' => $current?->isMatched() ? ['tmdb_id' => $current->tmdb_id, 'type' => $current->media_type, 'manual' => $current->isManual()] : null,
            'candidates' => $candidates,
        ]);
    }

    /**
     * Link the folder to the chosen title and store its data. Accepts a TMDb id (with its type) or a themoviedb.org link.
     */
    public function assign(Request $request)
    {
        $request->validate([
            'tmdb_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'in:movie,tv'],
            'reference' => ['nullable', 'string', 'max:300'],
        ]);

        $folder = $this->folder($request);

        if ($request->filled('reference')) {
            [$type, $id] = $this->parseReference((string) $request->input('reference')) ?? [null, null];
        } else {
            [$type, $id] = [$request->input('type'), $request->integer('tmdb_id')];
        }

        if (! $type || ! $id) {
            return $this->respond($request, false, 'شناسه یا لینک TMDb نامعتبر است (مثال: https://www.themoviedb.org/movie/603).');
        }

        try {
            $title = $this->titles->assign($folder, (int) $id, $type);
        } catch (RuntimeException) {
            return $this->respond($request, false, 'این عنوان از TMDb دریافت نشد (شناسه نادرست یا ارتباط قطع است).');
        }

        return $this->respond($request, true, 'به «'.$title->title.'» وصل شد و اطلاعاتش ذخیره شد.');
    }

    public function refresh(Request $request)
    {
        $title = Title::where('folder', $this->folder($request))->first();
        abort_unless($title, 404);

        try {
            $this->titles->refresh($title);
        } catch (RuntimeException) {
            return $this->respond($request, false, 'به‌روزرسانی از TMDb ممکن نشد.');
        }

        return $this->respond($request, true, 'اطلاعات از TMDb دوباره دریافت شد.');
    }

    /**
     * Forget the link; the folder is matched from its name again the next time it is shown.
     */
    public function reset(Request $request)
    {
        $this->titles->reset($this->folder($request));

        return $this->respond($request, true, 'اتصال حذف شد؛ دفعه‌ی بعد دوباره از روی نام حدس زده می‌شود.');
    }

    /**
     * @return array{0: string, 1: int}|null type and id from "movie/603" or a themoviedb.org URL
     */
    private function parseReference(string $reference): ?array
    {
        if (preg_match('#themoviedb\.org/(movie|tv)/(\d+)#i', $reference, $m)) {
            return [strtolower($m[1]), (int) $m[2]];
        }

        if (preg_match('#^(movie|tv)\s*[/:]\s*(\d+)$#i', trim($reference), $m)) {
            return [strtolower($m[1]), (int) $m[2]];
        }

        return null;
    }

    /**
     * The request's top-level library folder; anything else is not a title.
     */
    private function folder(Request $request): string
    {
        $request->validate(['folder' => ['required', 'string']]);

        try {
            $folder = SafePath::name($request->input('folder'));
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless(Storage::disk('videos')->directoryExists($folder), 404);

        return $folder;
    }

    private function respond(Request $request, bool $ok, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }
}
