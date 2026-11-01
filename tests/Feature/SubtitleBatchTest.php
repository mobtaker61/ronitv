<?php

use App\Jobs\FetchSubtitle;
use App\Models\SubtitleLookup;
use App\Models\Title;
use App\Services\SubtitleBatch;
use App\Services\SubtitleFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
        'services.subsource.key' => 'k',
        'services.tmdb.key' => 't',
    ]);
    Storage::fake('videos');
    Cache::flush();
    Http::swap(new Factory);
});

function confirmed(string $folder, int $tmdbId = 1, string $by = 'manual'): void
{
    Title::create(['folder' => $folder, 'tmdb_id' => $tmdbId, 'media_type' => 'movie', 'matched_by' => $by, 'title' => $folder, 'original_title' => $folder, 'year' => 2014, 'imdb_id' => 'tt'.$tmdbId, 'fetched_at' => now()]);
}

function asBatchAdmin()
{
    return test()->withSession(['admin_auth' => true]);
}

function srtZip(): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('a.srt', "1\n00:00:01,000 --> 00:00:03,000\nسلام\n");
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

it('picks the videos that need a subtitle and skips the rest', function () {
    $disk = Storage::disk('videos');
    foreach (['Film/Film.mkv', 'Done/Done.mkv', 'Done/Done.fa.vtt', 'Missed/Missed.mkv', 'Conv/Conv.mkv', 'Conv/Conv.m3u8', 'Guess/Guess.mkv', 'Film/_cover.jpg'] as $f) {
        $disk->put($f, 'x');
    }
    foreach (['Film', 'Done', 'Missed', 'Conv'] as $i => $folder) {
        confirmed($folder, $i + 1);
    }
    confirmed('Guess', 9, 'auto');
    SubtitleLookup::create(['video_path' => 'Missed/Missed.mkv', 'lang' => 'fa', 'status' => 'none', 'checked_at' => now()]);

    $batch = app(SubtitleBatch::class);

    expect($batch->pending())->toBe(['Conv/Conv.m3u8', 'Film/Film.mkv'])        // confirmed only; original skipped for its playlist
        ->and($batch->pending(onlyConfirmed: false))->toBe(['Conv/Conv.m3u8', 'Film/Film.mkv', 'Guess/Guess.mkv'])
        ->and($batch->pending('Film', false))->toBe(['Film/Film.mkv']);
});

it('queues one spaced-out job per video and tracks the batch', function () {
    Queue::fake();
    $disk = Storage::disk('videos');
    foreach (['A/a.mkv', 'B/b.mkv', 'B/c.mkv'] as $f) {
        $disk->put($f, 'x');
    }
    confirmed('A', 1);
    confirmed('B', 2);

    $started = app(SubtitleBatch::class)->start();

    expect($started)->toMatchArray(['total' => 3, 'done' => 0]);
    Queue::assertPushed(FetchSubtitle::class, 3);
    Queue::assertPushed(FetchSubtitle::class, fn ($job) => $job->videoPath === 'B/c.mkv' && $job->batchId === $started['id']);
    expect(app(SubtitleBatch::class)->status()['running'])->toBeTrue();
});

it('refuses to start a second batch while one is running', function () {
    Queue::fake();
    Storage::disk('videos')->put('A/a.mkv', 'x');
    confirmed('A');
    app(SubtitleBatch::class)->start();

    expect(fn () => app(SubtitleBatch::class)->start())->toThrow(RuntimeException::class);
});

it('stores a found subtitle and counts it in the batch', function () {
    Storage::disk('videos')->put('Film/Film.2014.mkv', 'x');
    confirmed('Film', 7);
    Http::fake([
        'api.subsource.net/api/v1/movies/search*' => Http::response(['data' => [['movieId' => 70, 'season' => null]]]),
        'api.subsource.net/api/v1/subtitles?*' => Http::response(['data' => [['subtitleId' => 5, 'releaseInfo' => ['WEB-DL'], 'rating' => ['good' => 3, 'total' => 3], 'downloads' => 10, 'files' => 1]]]),
        'api.subsource.net/api/v1/subtitles/5/download' => Http::response(srtZip()),
    ]);
    Queue::fake();
    $batch = app(SubtitleBatch::class)->start();

    (new FetchSubtitle('Film/Film.2014.mkv', $batch['id']))->handle(app(SubtitleFinder::class), app(SubtitleBatch::class));

    Storage::disk('videos')->assertExists('Film/Film.2014.fa.vtt');
    expect(app(SubtitleBatch::class)->status())->toMatchArray(['done' => 1, 'stored' => 1, 'running' => false]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'imdb=tt7'));        // searched by the confirmed IMDb id
});

it('counts a title with no subtitles as "none" and remembers it', function () {
    Storage::disk('videos')->put('Film/Film.mkv', 'x');
    confirmed('Film', 7);
    Http::fake([
        'api.subsource.net/api/v1/movies/search*' => Http::response(['data' => [['movieId' => 70, 'season' => null]]]),
        'api.subsource.net/api/v1/subtitles?*' => Http::response(['data' => []]),
    ]);
    Queue::fake();
    $batch = app(SubtitleBatch::class)->start();

    (new FetchSubtitle('Film/Film.mkv', $batch['id']))->handle(app(SubtitleFinder::class), app(SubtitleBatch::class));

    expect(app(SubtitleBatch::class)->status())->toMatchArray(['done' => 1, 'none' => 1]);
    expect(SubtitleLookup::first()->status)->toBe('none');
    expect(app(SubtitleBatch::class)->pending())->toBe([]);               // not queued again
});

it('puts a rate-limited job back and reports it failed only after the last try', function () {
    Storage::disk('videos')->put('Film/Film.mkv', 'x');
    confirmed('Film', 7);
    Http::fake(['api.subsource.net/*' => Http::response('slow down', 429)]);
    Queue::fake();
    $batch = app(SubtitleBatch::class)->start();

    $job = Mockery::mock(FetchSubtitle::class, ['Film/Film.mkv', $batch['id']])->makePartial();
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('release')->once()->with(120);
    $job->handle(app(SubtitleFinder::class), app(SubtitleBatch::class));

    expect(app(SubtitleBatch::class)->status()['done'])->toBe(0);          // not counted yet: it will run again

    $last = Mockery::mock(FetchSubtitle::class, ['Film/Film.mkv', $batch['id']])->makePartial();
    $last->shouldReceive('attempts')->andReturn(3);
    $last->shouldNotReceive('release');
    $last->handle(app(SubtitleFinder::class), app(SubtitleBatch::class));

    expect(app(SubtitleBatch::class)->status())->toMatchArray(['done' => 1, 'failed' => 1]);
});

it('stops the whole batch when the API key is refused', function () {
    $disk = Storage::disk('videos');
    $disk->put('Film/a.mkv', 'x');
    $disk->put('Film/b.mkv', 'x');
    confirmed('Film', 7);
    Http::fake(['api.subsource.net/*' => Http::response('no', 401)]);
    Queue::fake();
    $batch = app(SubtitleBatch::class)->start();

    (new FetchSubtitle('Film/a.mkv', $batch['id']))->handle(app(SubtitleFinder::class), app(SubtitleBatch::class));
    $sent = count(Http::recorded());
    (new FetchSubtitle('Film/b.mkv', $batch['id']))->handle(app(SubtitleFinder::class), app(SubtitleBatch::class));

    expect(count(Http::recorded()))->toBe($sent);                           // the second job does not even try
    expect(app(SubtitleBatch::class)->status())->toMatchArray(['running' => false, 'error' => 'کلید SubSource پذیرفته نشد.']);
});

it('ignores reports that belong to an older batch', function () {
    Queue::fake();
    Storage::disk('videos')->put('A/a.mkv', 'x');
    confirmed('A');
    $batch = app(SubtitleBatch::class)->start();

    app(SubtitleBatch::class)->report('some-old-batch', 'stored');

    expect(app(SubtitleBatch::class)->status()['done'])->toBe(0)->and($batch['id'])->not->toBe('some-old-batch');
});

it('lets the admin start a batch for everything or one folder, and only the admin', function () {
    Queue::fake();
    Storage::disk('videos')->put('A/a.mkv', 'x');
    Storage::disk('videos')->put('B/b.mkv', 'x');
    confirmed('A', 1);
    confirmed('B', 2);

    $this->post('/admin/subtitles/batch')->assertRedirect(route('admin.login'));
    $this->getJson('/admin/subtitles/batch')->assertUnauthorized();

    asBatchAdmin()->postJson('/admin/subtitles/batch', ['folder' => 'A', 'only_confirmed' => 0])
        ->assertOk()->assertJsonPath('status.total', 1);
    Queue::assertPushed(FetchSubtitle::class, 1);

    asBatchAdmin()->postJson('/admin/subtitles/batch')->assertStatus(422);        // one is already running
    asBatchAdmin()->getJson('/admin/subtitles/batch')->assertOk()->assertJsonPath('total', 1);
    asBatchAdmin()->postJson('/admin/subtitles/batch', ['folder' => '../x'])->assertNotFound();
});

it('explains a missing key and an empty batch instead of failing', function () {
    config(['services.subsource.key' => null]);
    asBatchAdmin()->postJson('/admin/subtitles/batch')->assertStatus(422)->assertJsonFragment(['ok' => false]);

    config(['services.subsource.key' => 'k']);
    asBatchAdmin()->postJson('/admin/subtitles/batch')->assertOk()->assertJsonPath('status.total', 0);
});

it('shows the batch card and progress on the dashboard', function () {
    Queue::fake();
    Storage::disk('videos')->put('A/a.mkv', 'x');
    confirmed('A');
    app(SubtitleBatch::class)->start();

    asBatchAdmin()->get('/admin')->assertOk()->assertSee('0 از 1')->assertSee('در حال اجرا');
});
