<?php

namespace App\Services;

use App\Models\SubtitleLookup;
use App\Models\Title;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Which TMDb title each top-level library folder is, kept in the database.
 *
 * A folder is matched once, either by an automatic guess from its name (flagged "auto" for the admin to review)
 * or by the admin picking the right candidate ("manual"). After that every page and subtitle lookup reads the
 * stored record; TMDb is only called again when the admin asks for a refresh.
 */
class TitleCatalog
{
    /** A folder TMDb found nothing for is searched again after this many days. */
    private const RETRY_UNMATCHED_DAYS = 7;

    private const CAST_LIMIT = 12;

    public function __construct(private TmdbService $tmdb) {}

    /**
     * Stored records for many folders with a single query (folders without one are simply absent).
     *
     * @param  list<string>  $folders
     * @return array<string, Title>
     */
    public function forFolders(array $folders): array
    {
        try {
            return Title::whereIn('folder', $folders)->get()->keyBy('folder')->all();
        } catch (Throwable $e) {
            report($e);

            return []; // pages still work without TMDb info
        }
    }

    /**
     * The record for a folder; when there is none (or an unmatched one is due for a retry) the folder is matched
     * from its name. Returns null when TMDb has nothing, or cannot be reached right now.
     *
     * @param  string|null  $typeHint  tv for folders that contain seasons, movie for single films
     */
    public function forFolder(string $folder, ?string $typeHint = null, bool $resolve = true): ?Title
    {
        try {
            return $this->lookup($folder, $typeHint, $resolve);
        } catch (Throwable $e) {
            report($e);

            return null; // database or TMDb trouble must never take a public page down
        }
    }

    private function lookup(string $folder, ?string $typeHint, bool $resolve): ?Title
    {
        $title = Title::where('folder', $folder)->first();

        if ($title?->isMatched()) {
            return $title;
        }

        // A known miss is not searched again until it is due; a folder never searched is.
        if (($title && ! $this->dueForRetry($title)) || ! $resolve) {
            return null;
        }

        return $this->autoMatch($folder, $typeHint);
    }

    /**
     * Guess from the folder name: the first TMDb result wins. Nothing is stored when TMDb is unreachable.
     */
    public function autoMatch(string $folder, ?string $typeHint = null): ?Title
    {
        $suggestion = $this->suggest($folder);
        $candidates = $this->tmdb->searchCandidates($suggestion['query'], $typeHint, $suggestion['year']);

        if ($candidates === null) {
            return null;
        }

        if ($candidates === []) {
            Title::updateOrCreate(['folder' => $folder], ['tmdb_id' => null, 'media_type' => null, 'matched_by' => Title::AUTO, 'fetched_at' => now()]);

            return null;
        }

        try {
            return $this->assign($folder, $candidates[0]['tmdb_id'], $candidates[0]['type'], Title::AUTO);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Link a folder to a TMDb title and store everything TMDb tells us about it.
     *
     * @throws RuntimeException when TMDb cannot be reached or does not know the id
     */
    public function assign(string $folder, int $tmdbId, string $type, string $matchedBy = Title::MANUAL): Title
    {
        if (! in_array($type, ['movie', 'tv'], true)) {
            throw new RuntimeException('Unknown media type.');
        }

        $details = $this->tmdb->fetchDetails($tmdbId, $type);

        if ($details === null) {
            throw new RuntimeException('TMDb could not provide this title.');
        }

        $changed = Title::where('folder', $folder)->where(fn ($q) => $q->where('tmdb_id', '!=', $tmdbId)->orWhereNull('tmdb_id'))->exists();

        $title = Title::updateOrCreate(['folder' => $folder], [
            'tmdb_id' => $tmdbId,
            'media_type' => $type,
            'matched_by' => $matchedBy,
            'title' => (string) ($details['title'] ?? $details['name'] ?? ''),
            'original_title' => (string) ($details['original_title'] ?? $details['original_name'] ?? ''),
            'year' => ($date = (string) ($details['release_date'] ?? $details['first_air_date'] ?? '')) !== '' ? (int) substr($date, 0, 4) : null,
            'poster_path' => $details['poster_path'] ?? null,
            'imdb_id' => $details['imdb_id'] ?? $details['external_ids']['imdb_id'] ?? null,
            'details' => $this->slim($details),
            'fetched_at' => now(),
        ]);

        // A different title means earlier "no subtitle found" results were about the wrong film: search again.
        if ($changed) {
            $this->forgetSubtitleMisses($folder);
        }

        return $title;
    }

    /**
     * Re-download the stored title's data from TMDb (new ratings, a translated overview...).
     */
    public function refresh(Title $title): Title
    {
        if (! $title->isMatched()) {
            throw new RuntimeException('This folder is not linked to a TMDb title.');
        }

        return $this->assign($title->folder, $title->tmdb_id, $title->media_type, $title->matched_by);
    }

    /**
     * Drop the link so the folder is matched from its name again the next time it is shown.
     */
    public function reset(string $folder): void
    {
        Title::where('folder', $folder)->delete();
        $this->forgetSubtitleMisses($folder);
    }

    /**
     * The folder moved or was renamed at the top level: its record follows it.
     */
    public function renameFolder(string $from, string $to): void
    {
        Title::where('folder', $to)->delete();
        Title::where('folder', $from)->update(['folder' => $to]);
    }

    public function forgetFolder(string $folder): void
    {
        Title::where('folder', $folder)->delete();
    }

    /**
     * A sensible search for a folder: its name with separators removed and, if the video files carry one,
     * a release year (an ambiguous name is usually told apart by year).
     *
     * @return array{query: string, year: int|null}
     */
    public function suggest(string $folder): array
    {
        $query = trim(preg_replace('/\s+/', ' ', str_replace(['_', '.'], ' ', $folder)));
        $year = null;

        try {
            foreach (Storage::disk('videos')->allFiles($folder) as $file) {
                if (preg_match('/(?<![0-9])((?:19|20)\d{2})(?![0-9])/', basename($file), $m)) {
                    $year = (int) $m[1];
                    break;
                }
            }
        } catch (Throwable) {
            // a missing folder simply gives no year hint
        }

        return ['query' => $query, 'year' => $year];
    }

    private function dueForRetry(Title $title): bool
    {
        return $title->fetched_at === null || $title->fetched_at->lt(now()->subDays(self::RETRY_UNMATCHED_DAYS));
    }

    private function forgetSubtitleMisses(string $folder): void
    {
        try {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $folder);
            SubtitleLookup::where('video_path', 'like', $escaped.'/%')->where('status', SubtitleLookup::NONE)->delete();
        } catch (Throwable $e) {
            report($e); // only a cache of past searches
        }
    }

    /**
     * Keep what the pages use; drop bulky parts of the payload (full crew list, every cast member).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function slim(array $details): array
    {
        $details['credits'] = [
            'cast' => array_map(
                fn ($c) => ['name' => $c['name'] ?? '', 'character' => $c['character'] ?? '', 'profile_path' => $c['profile_path'] ?? null],
                array_slice($details['credits']['cast'] ?? [], 0, self::CAST_LIMIT),
            ),
        ];

        return $details;
    }
}
