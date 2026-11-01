<?php

namespace App\Console\Commands;

use App\Jobs\ProcessVideo;
use App\Services\Ffmpeg;
use App\Services\MediaProcessor;
use App\Services\VideoLibrary;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class MediaProcess extends Command
{
    protected $signature = 'media:process
        {path? : Video path relative to the videos disk}
        {--all : Every convertible video (MKV/AVI/MOV/WMV, and MP4 with several audio tracks)}
        {--audit : Only report what would happen, change nothing}
        {--delete-original : Delete the source file once the converted copy is verified}
        {--sync : Convert now in this process instead of queueing}
        {--limit=0 : Stop after this many files}';

    protected $description = 'Convert videos for browsers (MP4 / multi-audio HLS) and extract embedded subtitles';

    public function handle(Ffmpeg $ffmpeg, MediaProcessor $processor): int
    {
        $disk = Storage::disk('videos');
        $paths = $this->option('all') ? $this->allVideos($disk) : array_filter([$this->argument('path')]);

        if ($paths === []) {
            $this->error('Give a path or use --all.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $rows = [];
        $count = 0;

        foreach ($paths as $path) {
            try {
                $probe = $ffmpeg->probe($disk->path($path));
            } catch (\Throwable $e) {
                $rows[] = [$path, 'unreadable', '-', '-', '-', substr($e->getMessage(), 0, 40)];

                continue;
            }

            $audio = count($probe['audio']);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $plan = $audio > 1 ? 'hls' : ($extension === 'mp4' ? 'none' : 'mp4');
            $base = pathinfo($path, PATHINFO_FILENAME);
            $dir = dirname($path) === '.' ? '' : dirname($path).'/';
            $done = ($plan === 'hls' && $disk->exists($dir.$base.'.m3u8')) || ($plan === 'mp4' && $disk->exists($dir.$base.'.mp4'));
            $texts = count(array_filter($probe['subtitles'], fn ($s) => $s['text']));

            $rows[] = [
                $path,
                $done ? "$plan (done)" : $plan,
                $audio.' ('.implode(',', array_map(fn ($a) => $a['lang'] ?? '?', $probe['audio'])).')',
                $texts,
                number_format($probe['size'] / 1048576).' MB',
                $probe['video']['codec'] ?? '-',
            ];

            if ($this->option('audit') || $done || ($plan === 'none' && $texts === 0)) {
                continue;
            }

            if ($limit > 0 && $count >= $limit) {
                continue;
            }
            $count++;

            if ($this->option('sync')) {
                $this->info("Converting {$path} ...");
                $this->line(json_encode($processor->process($path, (bool) $this->option('delete-original')), JSON_UNESCAPED_UNICODE));
            } else {
                ProcessVideo::dispatch($path, (bool) $this->option('delete-original'));
            }
        }

        $this->table(['file', 'plan', 'audio', 'text subs', 'size', 'video'], $rows);

        if (! $this->option('audit') && ! $this->option('sync')) {
            $this->info("Queued {$count} file(s) on the 'media' queue.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function allVideos(Filesystem $disk): array
    {
        $files = array_filter(
            $disk->allFiles(),
            fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), VideoLibrary::UPLOAD_EXTENSIONS, true),
        );
        natcasesort($files);

        return array_values($files);
    }
}
