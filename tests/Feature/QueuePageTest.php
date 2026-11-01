<?php

use App\Jobs\DownloadRemoteVideo;
use App\Jobs\FetchSubtitle;
use App\Jobs\ProcessVideo;
use App\Models\Title;
use App\Services\QueueMonitor;
use App\Services\SubtitleBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
        'queue.default' => 'database',      // phpunit runs the queue synchronously; these tests need real rows
        'services.subsource.key' => 'k',
    ]);
    Storage::fake('videos');
    Cache::flush();
});

function queueAdmin()
{
    return test()->withSession(['admin_auth' => true]);
}

/** Turn the oldest waiting job into a failed one, the way a worker would leave it. */
function failOldestJob(string $message = 'RuntimeException: boom in /var/www/ronitv/app/Services/MediaProcessor.php:102'): string
{
    $job = DB::table('jobs')->orderBy('id')->first();
    DB::table('jobs')->where('id', $job->id)->delete();
    app('queue.failer')->log('database', $job->queue, $job->payload, new Exception($message));

    return DB::table('failed_jobs')->orderByDesc('id')->value('uuid');
}

it('is only for the admin', function () {
    $this->get('/admin/queue')->assertRedirect(route('admin.login'));
    $this->post('/admin/queue/retry')->assertRedirect(route('admin.login'));
    $this->post('/admin/queue/cancel', ['queue' => 'media'])->assertRedirect(route('admin.login'));
});

it('describes waiting, delayed and running jobs in words, never exposing a download URL', function () {
    ProcessVideo::dispatch('Show/S1/e1.mkv', true);
    FetchSubtitle::dispatch('Film/Film.mkv', 'batch1');
    DownloadRemoteVideo::dispatch('id1', 'https://user:secret@cdn.example.com/a/b.mp4?token=SECRET', 'Film/b.mp4');
    ProcessVideo::dispatch('Show/S1/e2.mkv')->delay(now()->addMinutes(5));
    DB::table('jobs')->orderBy('id')->limit(1)->update(['reserved_at' => now()->subSeconds(75)->timestamp, 'attempts' => 1]);

    $q = app(QueueMonitor::class)->snapshot();

    expect($q['running'])->toHaveCount(1)
        ->and($q['running'][0])->toMatchArray(['title' => 'تبدیل ویدیو', 'label' => 'Show/S1/e1.mkv', 'detail' => 'حذف اصل بعد از تأیید'])
        ->and($q['running'][0]['runningFor'])->toBeGreaterThanOrEqual(75)
        ->and($q['waitingTotal'])->toBe(3)
        ->and($q['readyNow'])->toBe(2);

    $labels = array_column($q['waiting'], 'label');
    expect($labels)->toContain('Film/Film.mkv', 'Film/b.mp4');
    $download = collect($q['waiting'])->firstWhere('class', 'DownloadRemoteVideo');
    expect($download['detail'])->toBe('از cdn.example.com');

    $page = queueAdmin()->get('/admin/queue')->assertOk()->assertSee('Show/S1/e1.mkv')->assertSee('زیرنویس')->assertSee('از cdn.example.com');
    expect($page->getContent())->not->toContain('SECRET')->not->toContain('token=');

    $scheduled = collect($q['waiting'])->firstWhere('label', 'Show/S1/e2.mkv');
    expect($scheduled['scheduledIn'])->toBeGreaterThan(200);
});

it('returns just the live fragment for the auto-refresh', function () {
    ProcessVideo::dispatch('Show/e1.mkv');

    $partial = queueAdmin()->get('/admin/queue?partial=1')->assertOk()->assertSee('Show/e1.mkv');

    expect($partial->getContent())->not->toContain('<html')->not->toContain('requeue');
});

it('lists failures with a path-free reason and retries or forgets them', function () {
    ProcessVideo::dispatch('Show/e1.mkv', true);
    ProcessVideo::dispatch('Show/e2.mkv', true);
    $first = failOldestJob();
    $second = failOldestJob('RuntimeException: Output duration 21.4s differs from source 2725.2s. in /var/www/ronitv/app/Services/MediaProcessor.php:437');

    $page = queueAdmin()->get('/admin/queue')->assertOk();
    $page->assertSee('RuntimeException: boom')->assertSee('فایل مبدأ ناقص است');
    expect($page->getContent())->not->toContain('/var/www/ronitv');

    queueAdmin()->post('/admin/queue/retry', ['uuid' => $first])->assertSessionHas('success');
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    queueAdmin()->post('/admin/queue/forget', ['uuid' => $second])->assertSessionHas('success');
    expect(DB::table('failed_jobs')->count())->toBe(0);

    queueAdmin()->post('/admin/queue/retry')->assertSessionHas('error');         // nothing left to retry
});

it('retries and forgets everything at once', function () {
    foreach (['a', 'b', 'c'] as $name) {
        ProcessVideo::dispatch("Show/{$name}.mkv");
    }
    failOldestJob();
    failOldestJob();
    failOldestJob();

    queueAdmin()->post('/admin/queue/retry')->assertSessionHas('success');
    expect(DB::table('jobs')->count())->toBe(3)->and(DB::table('failed_jobs')->count())->toBe(0);

    failOldestJob();
    queueAdmin()->post('/admin/queue/forget')->assertSessionHas('success');
    expect(DB::table('failed_jobs')->count())->toBe(0);
});

it('cancels waiting jobs but never one that is running', function () {
    ProcessVideo::dispatch('Show/running.mkv');
    ProcessVideo::dispatch('Show/waiting.mkv');
    FetchSubtitle::dispatch('Film/a.mkv', 'b');
    $running = DB::table('jobs')->orderBy('id')->first();
    DB::table('jobs')->where('id', $running->id)->update(['reserved_at' => now()->timestamp]);

    queueAdmin()->post('/admin/queue/cancel', ['id' => $running->id])->assertSessionHas('error');
    expect(DB::table('jobs')->count())->toBe(3);

    $waiting = DB::table('jobs')->where('queue', 'media')->whereNull('reserved_at')->value('id');
    queueAdmin()->post('/admin/queue/cancel', ['id' => $waiting])->assertSessionHas('success');
    expect(DB::table('jobs')->count())->toBe(2);

    queueAdmin()->post('/admin/queue/cancel', ['queue' => 'default'])->assertSessionHas('success');
    expect(DB::table('jobs')->count())->toBe(1);                                     // only the running one is left

    queueAdmin()->post('/admin/queue/cancel')->assertSessionHas('error');
    queueAdmin()->post('/admin/queue/cancel', ['queue' => 'other'])->assertSessionHasErrors('queue');
});

it('stops a running subtitle batch when its waiting jobs are cancelled', function () {
    Storage::disk('videos')->put('A/a.mkv', 'x');
    Title::create(['folder' => 'A', 'tmdb_id' => 1, 'media_type' => 'movie', 'matched_by' => 'manual', 'title' => 'A', 'fetched_at' => now()]);
    $batch = app(SubtitleBatch::class)->start();
    expect(app(SubtitleBatch::class)->status()['running'])->toBeTrue();

    queueAdmin()->post('/admin/queue/cancel', ['queue' => 'default'])->assertSessionHas('success');

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(app(SubtitleBatch::class)->status())->toMatchArray(['id' => $batch['id'], 'running' => false, 'aborted' => true]);
});

it('queues only the videos that are not converted and not already waiting', function () {
    $disk = Storage::disk('videos');
    foreach (['A/a.mkv', 'B/b.mkv', 'B/b.m3u8', 'C/c.mkv', 'C/c.mp4', 'D/d.mkv', 'E/e.mp4', 'F/f.avi'] as $file) {
        $disk->put($file, 'x');
    }
    ProcessVideo::dispatch('D/d.mkv');         // already waiting

    $count = app(QueueMonitor::class)->requeueConversions(true);

    expect($count)->toBe(2);                    // A/a.mkv and F/f.avi
    $paths = collect(DB::table('jobs')->get())->map(fn ($j) => app(QueueMonitor::class)->describe($j->payload))->pluck('label')->sort()->values()->all();
    expect($paths)->toBe(['A/a.mkv', 'D/d.mkv', 'F/f.avi']);
    expect(app(QueueMonitor::class)->requeueConversions(true))->toBe(0);     // idempotent

    queueAdmin()->post('/admin/queue/requeue')->assertSessionHas('error');
});

it('carries the delete-original choice into the queued conversions', function () {
    Storage::disk('videos')->put('A/a.mkv', 'x');

    queueAdmin()->post('/admin/queue/requeue', ['delete_original' => 1])->assertSessionHas('success');

    expect(app(QueueMonitor::class)->describe(DB::table('jobs')->value('payload'))['detail'])->toBe('حذف اصل بعد از تأیید');
});

it('tells a stalled worker from an idle one', function () {
    $monitor = app(QueueMonitor::class);

    ProcessVideo::dispatch('Show/e1.mkv');
    expect($monitor->snapshot()['worker']['stalled'])->toBeTrue();               // work is ready, nobody has ever picked up

    event(new Looping('database', 'media'));                                       // the worker's heartbeat
    expect($monitor->snapshot()['worker']['stalled'])->toBeFalse();

    Cache::put(QueueMonitor::HEARTBEAT_KEY, now()->subMinutes(10)->timestamp);
    expect($monitor->snapshot()['worker']['stalled'])->toBeTrue();
    queueAdmin()->get('/admin/queue')->assertSee('worker متوقف');

    DB::table('jobs')->update(['reserved_at' => now()->timestamp]);                // a job is running: the worker is clearly alive
    expect($monitor->snapshot()['worker']['stalled'])->toBeFalse();

    DB::table('jobs')->delete();
    Cache::forget(QueueMonitor::HEARTBEAT_KEY);
    expect($monitor->snapshot()['worker']['stalled'])->toBeFalse();               // an empty queue is never "stalled"
});
