<?php

namespace App\Services;

use App\Exceptions\SubtitleTitleNotFound;
use App\Models\SubtitleLookup;
use App\Support\SubtitleConverter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Finds a Persian subtitle for a library video on SubSource and stores it as "<video>.fa.vtt".
 *
 * The stored sidecar is the cache: once it exists nothing here talks to the API again.
 * Misses are remembered too (subtitle_lookups) so the daily quota is not spent on titles that have none.
 */
class SubtitleFinder
{
    private const LANG = 'fa';

    private const MAX_DOWNLOAD_ATTEMPTS = 4;

    private const MAX_ENTRY_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private SubSourceClient $api,
        private TmdbService $tmdb,
    ) {}

    /**
     * Is there already a Persian subtitle (.fa.vtt/.fa.srt, or an un-suffixed .srt/.vtt which counts as Persian)?
     */
    public function hasLocal(string $videoPath): bool
    {
        $disk = Storage::disk('videos');
        $stem = $this->stem($videoPath);

        foreach (['.fa.vtt', '.fa.srt', '.vtt', '.srt'] as $suffix) {
            if ($disk->exists($stem.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ranked online candidates for a video (cached for an hour so browsing the list costs no quota).
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(string $videoPath): array
    {
        return Cache::remember('subsource:candidates:'.md5($videoPath), 3600, function () use ($videoPath) {
            $movieId = $this->movieId($videoPath);
            $tokens = $this->tokens(basename($videoPath));

            $list = array_map(function (array $item) use ($tokens) {
                $release = implode(' ', (array) ($item['releaseInfo'] ?? []));
                $good = (int) ($item['rating']['good'] ?? 0);
                $total = (int) ($item['rating']['total'] ?? 0);
                $overlap = count(array_intersect($tokens, $this->tokens($release)));
                $hearingImpaired = (bool) ($item['hearingImpaired'] ?? false);

                return [
                    'id' => (int) $item['subtitleId'],
                    'release' => $release,
                    'comment' => (string) ($item['commentary'] ?? ''),
                    'files' => (int) ($item['files'] ?? 1),
                    'downloads' => (int) ($item['downloads'] ?? 0),
                    'good' => $good,
                    'total' => $total,
                    'hearing_impaired' => $hearingImpaired,
                    'score' => $overlap * 10
                        + ($total > 0 ? round($good / $total * 20, 1) : 0)
                        + min(10, log10(1 + (int) ($item['downloads'] ?? 0)) * 3)
                        - ($hearingImpaired ? 15 : 0),
                ];
            }, $this->api->subtitles($movieId));

            usort($list, fn ($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($list, 0, 30);
        });
    }

    /**
     * Download and store a subtitle: the chosen one, or the best candidates in order.
     *
     * @return array{status: string, subtitle_id?: int, release?: string}
     */
    public function fetch(string $videoPath, ?int $subtitleId = null): array
    {
        if ($this->hasLocal($videoPath)) {
            return ['status' => 'exists'];
        }

        $lookup = SubtitleLookup::where('video_path', $videoPath)->first();

        if ($subtitleId === null && $lookup) {
            // Remembered result: a miss is not retried for a while, and a known subtitle costs one download, no search.
            if ($lookup->status === SubtitleLookup::NONE && $lookup->checked_at->gt(now()->subDays(config('services.subsource.miss_days')))) {
                return ['status' => 'none'];
            }
            if ($lookup->status === SubtitleLookup::FOUND && $lookup->subtitle_id) {
                $subtitleId = $lookup->subtitle_id;
            }
        }

        try {
            $ids = $subtitleId !== null
                ? [['id' => $subtitleId, 'release' => $lookup?->release ?? '']]
                : array_slice($this->candidates($videoPath), 0, self::MAX_DOWNLOAD_ATTEMPTS);
        } catch (SubtitleTitleNotFound) {
            $this->remember($videoPath, SubtitleLookup::NONE);

            return ['status' => 'none'];
        }

        if ($ids === []) {
            $this->remember($videoPath, SubtitleLookup::NONE);

            return ['status' => 'none'];
        }

        $episode = self::parseEpisode(basename($videoPath));

        foreach ($ids as $candidate) {
            try {
                [$content, $extension] = $this->extract($this->api->download($candidate['id']), $episode, $this->isSeries($videoPath));
                [$vtt, $cues] = SubtitleConverter::stripPromoCues(SubtitleConverter::toVtt($content, $extension));

                if ($cues === 0) {
                    continue;
                }
            } catch (RuntimeException|InvalidArgumentException) {
                continue; // wrong episode in the pack, broken archive, not a subtitle: try the next one
            }

            Storage::disk('videos')->put($this->stem($videoPath).'.'.self::LANG.'.vtt', $vtt);
            $this->remember($videoPath, SubtitleLookup::FOUND, $candidate['id'], $candidate['release'] ?? null);

            return ['status' => 'stored', 'subtitle_id' => $candidate['id'], 'release' => $candidate['release'] ?? ''];
        }

        $this->remember($videoPath, SubtitleLookup::NONE);

        return ['status' => 'none'];
    }

    /**
     * @return array{season: ?int, episode: int}|null
     */
    public static function parseEpisode(string $name): ?array
    {
        $name = pathinfo($name, PATHINFO_FILENAME);

        if (preg_match('/s(\d{1,2})[ ._-]*e(\d{1,3})/i', $name, $m) || preg_match('/(?<![0-9])(\d{1,2})x(\d{2,3})(?![0-9])/i', $name, $m)) {
            return ['season' => (int) $m[1], 'episode' => (int) $m[2]];
        }

        if (preg_match('/(?<![a-z0-9])e(?:p|pisode)?[ ._-]?(\d{1,3})(?![0-9a-z])/i', $name, $m)) {
            return ['season' => null, 'episode' => (int) $m[1]];
        }

        return null;
    }

    private function movieId(string $videoPath): int
    {
        $title = $this->titleFolder($videoPath);
        $season = $this->season($videoPath);
        $type = $season !== null ? 'tv' : 'movie';

        $found = $this->tmdb->search($title, $type);
        $details = isset($found['id']) ? $this->tmdb->details($found['id'], $type) : null;
        $imdb = $details['external_ids']['imdb_id'] ?? $details['imdb_id'] ?? null;
        $year = (int) substr((string) ($details['release_date'] ?? $details['first_air_date'] ?? ''), 0, 4) ?: null;

        return Cache::remember('subsource:movie:'.md5("{$title}|{$season}"), 86400 * 30, function () use ($imdb, $title, $year, $season) {
            $results = $this->api->searchMovies($imdb, $title, $year, $season);

            if ($season !== null) {
                $results = array_values(array_filter($results, fn ($r) => (int) ($r['season'] ?? 0) === $season)) ?: [];
            }

            if ($results === []) {
                throw new SubtitleTitleNotFound('Title not found on SubSource.');
            }

            return (int) $results[0]['movieId'];
        });
    }

    /**
     * Pick the subtitle for this video out of a downloaded ZIP: a pack holds one file per episode.
     *
     * @param  array{season: ?int, episode: int}|null  $episode
     * @return array{0: string, 1: string} content and extension
     */
    private function extract(string $zipBytes, ?array $episode, bool $series): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sub');
        file_put_contents($tmp, $zipBytes);
        $zip = new ZipArchive;

        try {
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('Unreadable archive.');
            }

            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                if (str_starts_with($name, '__MACOSX') || ! in_array($extension, ['srt', 'vtt'], true) || $stat['size'] > self::MAX_ENTRY_BYTES) {
                    continue;
                }

                $entries[] = ['index' => $i, 'name' => basename($name), 'ext' => $extension, 'size' => $stat['size']];
            }

            if ($entries === []) {
                throw new RuntimeException('No subtitle in archive.');
            }

            $chosen = null;
            if ($series && $episode) {
                foreach ($entries as $entry) {
                    $parsed = self::parseEpisode($entry['name']);
                    if ($parsed && $parsed['episode'] === $episode['episode']
                        && ($parsed['season'] === null || $episode['season'] === null || $parsed['season'] === $episode['season'])) {
                        $chosen = $entry;
                        break;
                    }
                }
                // A one-file archive for a series is only accepted when its name carries no other episode number.
                if (! $chosen && count($entries) === 1 && self::parseEpisode($entries[0]['name']) === null) {
                    $chosen = $entries[0];
                }
            } else {
                usort($entries, fn ($a, $b) => $b['size'] <=> $a['size']);
                $chosen = $entries[0];
            }

            if (! $chosen) {
                throw new RuntimeException('Episode not in archive.');
            }

            return [(string) $zip->getFromIndex($chosen['index']), $chosen['ext']];
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    private function remember(string $videoPath, string $status, ?int $subtitleId = null, ?string $release = null): void
    {
        SubtitleLookup::updateOrCreate(['video_path' => $videoPath], [
            'lang' => self::LANG,
            'status' => $status,
            'subtitle_id' => $subtitleId,
            'release' => $release,
            'checked_at' => now(),
        ]);
    }

    private function stem(string $videoPath): string
    {
        $dir = dirname($videoPath) === '.' ? '' : dirname($videoPath).'/';

        return $dir.pathinfo($videoPath, PATHINFO_FILENAME);
    }

    /**
     * The top-level library folder names the title ("Show/S1/e1.mkv" -> "Show").
     */
    private function titleFolder(string $videoPath): string
    {
        return explode('/', $videoPath)[0] === basename($videoPath)
            ? pathinfo($videoPath, PATHINFO_FILENAME)
            : explode('/', $videoPath)[0];
    }

    private function isSeries(string $videoPath): bool
    {
        return $this->season($videoPath) !== null;
    }

    private function season(string $videoPath): ?int
    {
        $parsed = self::parseEpisode(basename($videoPath));
        if ($parsed && $parsed['season'] !== null) {
            return $parsed['season'];
        }

        $segments = explode('/', $videoPath);
        if (count($segments) >= 3 && preg_match('/(\d{1,2})\s*$/', $segments[count($segments) - 2], $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Lower-case release tokens ("WEB-DL 480p" -> web, dl, 480p) used to compare a file with a subtitle's release.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = strtolower(preg_replace('/\.(mkv|mp4|avi|mov|wmv|m3u8)$/i', '', $text));

        return array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', $text),
            fn ($t) => $t !== '' && strlen($t) > 1,
        )));
    }
}
