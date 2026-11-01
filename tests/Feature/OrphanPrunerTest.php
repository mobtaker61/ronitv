<?php

use App\Jobs\ProcessVideo;
use App\Models\SubtitleLookup;
use App\Models\Title;
use App\Services\MediaProcessor;
use App\Services\OrphanPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
        'queue.default' => 'database',
    ]);
    Storage::fake('videos');
    Storage::fake('hls');
});

/** Write a file into a fake disk and give it an age. */
function aged(string $disk, string $path, int $daysOld, string $content = 'x'): void
{
    Storage::disk($disk)->put($path, $content);
    touch(Storage::disk($disk)->path($path), now()->subDays($daysOld)->timestamp - 60);
}

function hlsFolder(string $key, int $daysOld, int $files = 2): void
{
    foreach (range(1, $files) as $i) {
        aged('hls', "{$key}/seg_{$i}.m4s", $daysOld, str_repeat('s', 1000));
    }
}

/** The library as a healthy install leaves it, plus one of every kind of orphan. */
function libraryWithOrphans(): void
{
    // healthy: a converted video whose playlist points at a folder, with its subtitle
    aged('videos', 'Show/e1.m3u8', 5, "#EXTM3U\n/storage/hls/aaaaaaaaaaaaaaaa/p0.m3u8\n");
    aged('videos', 'Show/e1.fa.vtt', 5, "WEBVTT\n");
    aged('videos', 'Show/e2.mkv', 5);
    aged('videos', 'Show/e2.srt', 5);                       // un-suffixed subtitle of an existing video
    hlsFolder('aaaaaaaaaaaaaaaa', 5);

    // orphans
    hlsFolder('bbbbbbbbbbbbbbbb', 3);                        // no playlist points at it, old
    hlsFolder('cccccccccccccccc', 0);                        // no playlist, but written just now
    aged('videos', 'Show/gone.fa.vtt', 4, "WEBVTT\n");        // video deleted outside the app
    aged('videos', 'Show/new.fa.vtt', 0, "WEBVTT\n");         // fresh subtitle without a video
    aged('videos', 'Show/half.mkv.part', 3, 'partial');
    aged('videos', 'Show/live.mp4.part', 0, 'being written');

    Storage::disk('hls')->put('not-ours/keep.txt', 'a folder that is not named like a segment folder');

    Title::create(['folder' => 'Show', 'tmdb_id' => 1, 'media_type' => 'tv', 'matched_by' => 'manual', 'title' => 'Show']);
    Title::create(['folder' => 'Deleted', 'tmdb_id' => 2, 'media_type' => 'movie', 'matched_by' => 'auto', 'title' => 'Deleted']);
    SubtitleLookup::create(['video_path' => 'Show/e1.mkv', 'lang' => 'fa', 'status' => 'found', 'checked_at' => now()]);   // converted to e1.m3u8: still has a video
    SubtitleLookup::create(['video_path' => 'Gone/g.mkv', 'lang' => 'fa', 'status' => 'none', 'checked_at' => now()]);
}

it('finds only what nothing points at, and keeps what was touched within the grace period', function () {
    libraryWithOrphans();

    $found = app(OrphanPruner::class)->scan(1);

    expect(array_column($found['hls'], 'key'))->toBe(['bbbbbbbbbbbbbbbb'])
        ->and(array_column($found['sidecars'], 'path'))->toBe(['Show/gone.fa.vtt'])
        ->and(array_column($found['partials'], 'path'))->toBe(['Show/half.mkv.part'])
        ->and($found['titles'])->toBe(['Deleted'])
        ->and($found['lookups'])->toBe(1)
        ->and($found['recent'])->toBe(2)                         // the fresh HLS folder and the fresh subtitle
        ->and($found['bytes'])->toBeGreaterThan(2000);
});

it('removes the orphans and nothing else', function () {
    libraryWithOrphans();

    $removed = app(OrphanPruner::class)->prune(1);

    expect($removed)->toMatchArray(['hls' => 1, 'sidecars' => 1, 'partials' => 1, 'titles' => 1, 'lookups' => 1]);

    foreach (['Show/e1.m3u8', 'Show/e1.fa.vtt', 'Show/e2.mkv', 'Show/e2.srt', 'Show/new.fa.vtt', 'Show/live.mp4.part'] as $kept) {
        Storage::disk('videos')->assertExists($kept);
    }
    foreach (['Show/gone.fa.vtt', 'Show/half.mkv.part'] as $gone) {
        Storage::disk('videos')->assertMissing($gone);
    }
    Storage::disk('hls')->assertExists('aaaaaaaaaaaaaaaa/seg_1.m4s');
    Storage::disk('hls')->assertExists('cccccccccccccccc/seg_1.m4s');
    Storage::disk('hls')->assertExists('not-ours/keep.txt');
    Storage::disk('hls')->assertMissing('bbbbbbbbbbbbbbbb');
    expect(Title::pluck('folder')->all())->toBe(['Show'])
        ->and(SubtitleLookup::pluck('video_path')->all())->toBe(['Show/e1.mkv']);

    expect(app(OrphanPruner::class)->scan(1)['bytes'])->toBe(0);          // idempotent
});

it('with no grace period also removes the recent orphans, but never a fresh partial file', function () {
    libraryWithOrphans();

    app(OrphanPruner::class)->prune(0);

    Storage::disk('hls')->assertMissing('cccccccccccccccc');
    Storage::disk('videos')->assertMissing('Show/new.fa.vtt');
    Storage::disk('videos')->assertExists('Show/live.mp4.part');           // a running job may own it
});

it('never touches the folder of a conversion a worker is writing right now', function () {
    $key = MediaProcessor::hlsKey('Show', 'e5');
    hlsFolder($key, 3);                                                      // old enough to be pruned, no playlist yet
    ProcessVideo::dispatch('Show/e5.mkv');
    DB::table('jobs')->update(['reserved_at' => now()->timestamp]);          // a worker has picked it up

    app(OrphanPruner::class)->prune(0);
    Storage::disk('hls')->assertExists("{$key}/seg_1.m4s");

    DB::table('jobs')->delete();                                             // the job is gone: now it is an orphan
    app(OrphanPruner::class)->prune(0);
    Storage::disk('hls')->assertMissing($key);
});

it('falls back to the age guard when the queue table cannot be read', function () {
    hlsFolder('cccccccccccccccc', 0);                                        // written just now: maybe being built
    hlsFolder('bbbbbbbbbbbbbbbb', 3);
    Schema::drop('jobs');

    app(OrphanPruner::class)->prune(0);

    Storage::disk('hls')->assertExists('cccccccccccccccc/seg_1.m4s');
    Storage::disk('hls')->assertMissing('bbbbbbbbbbbbbbbb');
});

it('has a dry run that changes nothing', function () {
    libraryWithOrphans();

    $this->artisan('media:prune', ['--dry-run' => true, '--grace' => 1])
        ->expectsOutputToContain('bbbbbbbbbbbbbbbb')
        ->expectsOutputToContain('Would remove 1 HLS folder(s), 1 subtitle(s), 1 partial file(s), 1 title record(s), 1 lookup record(s)')
        ->assertSuccessful();

    Storage::disk('hls')->assertExists('bbbbbbbbbbbbbbbb/seg_1.m4s');
    expect(Title::count())->toBe(2);
});

it('runs hourly from the scheduler', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('media:prune --grace=1')->assertSuccessful();
});

it('shows the admin what is orphaned and lets them clean it at once', function () {
    libraryWithOrphans();
    // a guest first: withSession() sticks to the test instance, so every later request would be the admin's
    $this->post('/admin/prune')->assertRedirect(route('admin.login'));
    Storage::disk('hls')->assertExists('bbbbbbbbbbbbbbbb/seg_1.m4s');

    $admin = fn () => test()->withSession(['admin_auth' => true]);

    $admin()->get('/admin')->assertOk()->assertSee('فایل‌های بی‌صاحب پیدا شد')->assertSee('2 پوشه‌ی HLS بدون ویدیو');

    $admin()->post('/admin/prune')->assertSessionHas('success', fn ($m) => str_contains($m, 'پوشه‌ی HLS') && str_contains($m, 'مگابایت'));
    Storage::disk('hls')->assertMissing('bbbbbbbbbbbbbbbb');

    $admin()->get('/admin')->assertDontSee('فایل‌های بی‌صاحب پیدا شد');
    $admin()->post('/admin/prune')->assertSessionHas('success', 'مورد بی‌صاحبی پیدا نشد.');
});
