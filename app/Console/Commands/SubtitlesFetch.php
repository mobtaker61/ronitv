<?php

namespace App\Console\Commands;

use App\Exceptions\SubtitleTitleNotFound;
use App\Services\SubSourceClient;
use App\Services\SubtitleFinder;
use App\Services\VideoLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SubtitlesFetch extends Command
{
    protected $signature = 'subtitles:fetch
        {path? : One video path relative to the videos disk}
        {--all : Every video that has no Persian subtitle yet}
        {--limit=25 : Stop after this many videos that needed an online lookup}
        {--pause=1.2 : Seconds between online lookups (free keys allow 60 requests per minute)}';

    protected $description = 'Download missing Persian subtitles from SubSource (once per video, results are remembered)';

    public function handle(SubtitleFinder $finder, SubSourceClient $api): int
    {
        if (! $api->isConfigured()) {
            $this->error('SUBSOURCE_API_KEY is not set.');

            return self::FAILURE;
        }

        $disk = Storage::disk('videos');
        $paths = $this->option('all')
            ? array_values(array_filter($disk->allFiles(), fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), VideoLibrary::VIDEO_EXTENSIONS, true)))
            : array_filter([$this->argument('path')]);

        if ($paths === []) {
            $this->error('Give a path or use --all.');

            return self::FAILURE;
        }

        natcasesort($paths);
        $limit = (int) $this->option('limit');
        $pause = (int) round((float) $this->option('pause') * 1_000_000);
        $lookups = 0;
        $summary = ['stored' => 0, 'none' => 0, 'exists' => 0, 'error' => 0];

        foreach ($paths as $path) {
            // Converted originals are not looked up twice: one playable entry per name.
            if (str_ends_with(strtolower($path), '.mkv') && $disk->exists(preg_replace('/\.mkv$/i', '.m3u8', $path))) {
                continue;
            }

            if ($finder->hasLocal($path)) {
                $summary['exists']++;

                continue;
            }

            if ($limit > 0 && $lookups >= $limit) {
                $this->warn("Limit of {$limit} lookups reached; run again later to continue.");
                break;
            }

            try {
                $result = $finder->fetch($path);
            } catch (SubtitleTitleNotFound) {
                $result = ['status' => 'none'];
            } catch (RuntimeException $e) {
                $this->error("{$path}: {$e->getMessage()}");
                $summary['error']++;

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), '401')) {
                    $this->error('Stopping: the service refused the request (quota or key).');
                    break;
                }

                continue;
            }

            $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
            $this->line(sprintf('%-8s %s%s', $result['status'], $path, isset($result['release']) ? '  ['.$result['release'].']' : ''));
            $lookups++;
            usleep($pause);
        }

        $this->info('Done: '.json_encode($summary));

        return self::SUCCESS;
    }
}
