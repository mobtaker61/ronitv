<?php

namespace App\Console\Commands;

use App\Services\OrphanPruner;
use Illuminate\Console\Command;

class MediaPrune extends Command
{
    protected $signature = 'media:prune
        {--dry-run : List what would be removed and change nothing}
        {--grace=1 : Keep orphans touched within this many days (0 removes everything unreferenced)}';

    protected $description = 'Remove orphaned HLS folders, subtitles without a video, stale partial files and dead database records';

    public function handle(OrphanPruner $pruner): int
    {
        $grace = max(0, (int) $this->option('grace'));

        if ($this->option('dry-run')) {
            $found = $pruner->scan($grace);

            foreach ($found['hls'] as $folder) {
                $this->line(sprintf('hls      %s  %s MB  %d day(s) old', $folder['key'], number_format($folder['bytes'] / 1048576), $folder['age_days']));
            }
            foreach ($found['sidecars'] as $file) {
                $this->line('subtitle '.$file['path']);
            }
            foreach ($found['partials'] as $file) {
                $this->line('partial  '.$file['path']);
            }
            foreach ($found['titles'] as $folder) {
                $this->line('title    '.$folder);
            }

            $this->info(sprintf(
                'Would remove %d HLS folder(s), %d subtitle(s), %d partial file(s), %d title record(s), %d lookup record(s): %s MB. %d recent item(s) kept.',
                count($found['hls']), count($found['sidecars']), count($found['partials']), count($found['titles']), $found['lookups'],
                number_format($found['bytes'] / 1048576), $found['recent'],
            ));

            return self::SUCCESS;
        }

        $removed = $pruner->prune($grace);

        if (array_sum($removed) > 0) {
            $this->info(sprintf(
                'Removed %d HLS folder(s), %d subtitle(s), %d partial file(s), %d title record(s), %d lookup record(s): %s MB freed.',
                $removed['hls'], $removed['sidecars'], $removed['partials'], $removed['titles'], $removed['lookups'], number_format($removed['bytes'] / 1048576),
            ));
        }

        return self::SUCCESS;
    }
}
