<?php

use App\Models\SubtitleLookup;
use App\Services\SubtitleFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const SRT_FA = "1\n00:00:01,000 --> 00:00:03,000\nسلام\n";

function zipOf(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function subsourceItem(int $id, string $release, int $good = 10, int $total = 10, bool $hi = false): array
{
    return [
        'subtitleId' => $id, 'releaseInfo' => explode(' ', $release), 'commentary' => '', 'files' => 1,
        'downloads' => 100, 'rating' => ['good' => $good, 'bad' => $total - $good, 'total' => $total], 'hearingImpaired' => $hi,
    ];
}

/** Http::fake() stubs accumulate (the first match wins), so start every scenario from a clean client. */
function resetHttp(): void
{
    Http::swap(new Factory);
}

function fakeServices(array $subtitles, array $archives = [], ?array $movies = null): void
{
    resetHttp();
    config(['services.tmdb.key' => 't', 'services.subsource.key' => 'k']);

    $fakes = [
        'api.themoviedb.org/3/search/*' => Http::response(['results' => [['id' => 1]]]),
        'api.themoviedb.org/3/tv/*' => Http::response(['name' => 'Show', 'first_air_date' => '2016-09-20', 'external_ids' => ['imdb_id' => 'tt5555']]),
        'api.themoviedb.org/3/movie/*' => Http::response(['title' => 'Film', 'release_date' => '2013-01-01', 'imdb_id' => 'tt7777']),
        'api.subsource.net/api/v1/movies/search*' => Http::response(['data' => $movies ?? [['movieId' => 900, 'season' => 1], ['movieId' => 901, 'season' => 2]]]),
        'api.subsource.net/api/v1/subtitles?*' => Http::response(['data' => $subtitles]),
    ];
    foreach ($archives as $id => $bytes) {
        $fakes["api.subsource.net/api/v1/subtitles/{$id}/download"] = Http::response($bytes);
    }

    Http::fake($fakes);
}

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
    ]);
    Storage::fake('videos');
    Cache::flush();
});

it('parses episode numbers from file names', function (string $name, ?array $expected) {
    expect(SubtitleFinder::parseEpisode($name))->toBe($expected);
})->with([
    ['This.Is.Us.S01E04.480p.WEB-DL.mkv', ['season' => 1, 'episode' => 4]],
    ['show s2 e10.mkv', ['season' => 2, 'episode' => 10]],
    ['Show 3x07.mp4', ['season' => 3, 'episode' => 7]],
    ['Show.E05.mkv', ['season' => null, 'episode' => 5]],
    ['Wild.2014.720p.mkv', null],
    ['Film.2013.1080p.x264.mkv', null],
]);

it('downloads the best Persian subtitle once and keeps it next to the video', function () {
    Storage::disk('videos')->put('Film/Film.2013.WEB-DL.mkv', 'x');
    fakeServices(
        [subsourceItem(1, 'BluRay 1080p', 9, 10), subsourceItem(2, 'WEB-DL 480p', 9, 10)],
        [2 => zipOf(['Film.srt' => SRT_FA]), 1 => zipOf(['x.srt' => SRT_FA])],
        [['movieId' => 777, 'season' => null]],
    );

    $result = app(SubtitleFinder::class)->fetch('Film/Film.2013.WEB-DL.mkv');

    expect($result)->toMatchArray(['status' => 'stored', 'subtitle_id' => 2]);   // WEB-DL matches the file
    Storage::disk('videos')->assertExists('Film/Film.2013.WEB-DL.fa.vtt');
    expect(Storage::disk('videos')->get('Film/Film.2013.WEB-DL.fa.vtt'))->toStartWith('WEBVTT')->toContain('سلام');
    expect(SubtitleLookup::first())->status->toBe('found')->subtitle_id->toBe(2);

    // second call: the sidecar exists, so nothing is searched or downloaded again
    resetHttp();
    Http::fake();
    expect(app(SubtitleFinder::class)->fetch('Film/Film.2013.WEB-DL.mkv'))->toBe(['status' => 'exists']);
    Http::assertNothingSent();
});

it('picks the right episode out of a season pack', function () {
    Storage::disk('videos')->put('Show/S01/Show.S01E02.mkv', 'x');
    fakeServices(
        [subsourceItem(5, 'WEB-DL')],
        [5 => zipOf([
            'Show.S01E01.srt' => "1\n00:00:01,000 --> 00:00:02,000\nقسمت یک\n",
            'Show.S01E02.srt' => "1\n00:00:01,000 --> 00:00:02,000\nقسمت دو\n",
            '__MACOSX/Show.S01E02.srt' => 'junk',
        ])],
    );

    expect(app(SubtitleFinder::class)->fetch('Show/S01/Show.S01E02.mkv')['status'])->toBe('stored');
    expect(Storage::disk('videos')->get('Show/S01/Show.S01E02.fa.vtt'))->toContain('قسمت دو')->not->toContain('قسمت یک');
});

it('skips archives that do not contain the episode and tries the next candidate', function () {
    Storage::disk('videos')->put('Show/S01/Show.S01E03.mkv', 'x');
    fakeServices(
        [subsourceItem(6, 'WEB-DL', 10, 10), subsourceItem(7, 'WEB-DL', 5, 10)],
        [6 => zipOf(['Show.S01E09.srt' => SRT_FA]), 7 => zipOf(['Show.S01E03.srt' => "1\n00:00:01,000 --> 00:00:02,000\nدرست\n"])],
    );

    $result = app(SubtitleFinder::class)->fetch('Show/S01/Show.S01E03.mkv');

    expect($result)->toMatchArray(['status' => 'stored', 'subtitle_id' => 7]);
});

it('remembers a miss and does not spend quota on it again', function () {
    Storage::disk('videos')->put('Film/Film.mkv', 'x');
    fakeServices([], [], [['movieId' => 777, 'season' => null]]);

    expect(app(SubtitleFinder::class)->fetch('Film/Film.mkv'))->toBe(['status' => 'none']);
    expect(SubtitleLookup::first()->status)->toBe('none');

    resetHttp();
    Http::fake();
    expect(app(SubtitleFinder::class)->fetch('Film/Film.mkv'))->toBe(['status' => 'none']);
    Http::assertNothingSent();

    // after the miss window it is searched again (the cached empty candidate list expires on its own in an hour)
    Cache::flush();
    SubtitleLookup::query()->update(['checked_at' => now()->subDays(8)]);
    fakeServices([subsourceItem(3, 'x')], [3 => zipOf(['a.srt' => SRT_FA])], [['movieId' => 777, 'season' => null]]);
    expect(app(SubtitleFinder::class)->fetch('Film/Film.mkv')['status'])->toBe('stored');
});

it('records unknown titles as a miss but not service outages', function () {
    Storage::disk('videos')->put('Film/Film.mkv', 'x');
    fakeServices([], [], []);   // search returns no movie
    expect(app(SubtitleFinder::class)->fetch('Film/Film.mkv'))->toBe(['status' => 'none']);

    SubtitleLookup::query()->delete();
    Cache::flush();
    resetHttp();
    config(['services.tmdb.key' => 't', 'services.subsource.key' => 'k']);
    Http::fake([
        'api.themoviedb.org/*' => Http::response(['results' => [['id' => 1]], 'imdb_id' => 'tt1']),
        'api.subsource.net/*' => Http::response('down', 500),
    ]);

    expect(fn () => app(SubtitleFinder::class)->fetch('Film/Film.mkv'))->toThrow(RuntimeException::class);
    expect(SubtitleLookup::count())->toBe(0);   // an outage must not poison the cache
});

it('re-downloads a known subtitle by id after the sidecar was deleted, without searching', function () {
    Storage::disk('videos')->put('Film/Film.mkv', 'x');
    SubtitleLookup::create(['video_path' => 'Film/Film.mkv', 'lang' => 'fa', 'status' => 'found', 'subtitle_id' => 42, 'checked_at' => now()]);
    config(['services.subsource.key' => 'k']);
    Http::fake(['api.subsource.net/api/v1/subtitles/42/download' => Http::response(zipOf(['f.srt' => SRT_FA]))]);

    expect(app(SubtitleFinder::class)->fetch('Film/Film.mkv'))->toMatchArray(['status' => 'stored', 'subtitle_id' => 42]);
    Http::assertSentCount(1);
});

it('rejects hostile archives', function () {
    Storage::disk('videos')->put('Film/Film.mkv', 'x');
    fakeServices([subsourceItem(8, 'x')], [8 => 'not a zip at all'], [['movieId' => 777, 'season' => null]]);

    expect(app(SubtitleFinder::class)->fetch('Film/Film.mkv')['status'])->toBe('none');
    Storage::disk('videos')->assertMissing('Film/Film.fa.vtt');
});

it('lets only the admin search and fetch online subtitles', function () {
    $this->getJson('/subtitles/online?video=Film/Film.mkv')->assertUnauthorized();
    $this->postJson('/subtitles/online', ['video' => 'Film/Film.mkv'])->assertUnauthorized();
});

it('serves candidates and stores a chosen subtitle through the admin endpoints', function () {
    Storage::disk('videos')->put('Film/Film.2013.mkv', 'x');
    fakeServices([subsourceItem(11, 'WEB-DL'), subsourceItem(12, 'BluRay')], [12 => zipOf(['a.srt' => SRT_FA])], [['movieId' => 777, 'season' => null]]);

    $this->withSession(['admin_auth' => true])->getJson('/subtitles/online?video=Film/Film.2013.mkv')
        ->assertOk()
        ->assertJsonCount(2, 'candidates')
        ->assertJsonPath('local', false);

    $this->withSession(['admin_auth' => true])->postJson('/subtitles/online', ['video' => 'Film/Film.2013.mkv', 'subtitle_id' => 12])
        ->assertOk()->assertJsonPath('status', 'stored')->assertJsonPath('subtitle_id', 12);

    Storage::disk('videos')->assertExists('Film/Film.2013.fa.vtt');

    $this->withSession(['admin_auth' => true])->getJson('/subtitles/online?video=Film/Film.2013.mkv')->assertJsonPath('local', true);
    $this->withSession(['admin_auth' => true])->getJson('/subtitles/online?video=../../etc/passwd')->assertNotFound();
});

it('reports a missing API key instead of failing', function () {
    config(['services.subsource.key' => null]);
    Storage::disk('videos')->put('Film/Film.mkv', 'x');

    $this->withSession(['admin_auth' => true])
        ->getJson('/subtitles/online?video=Film/Film.mkv')->assertStatus(503);
});

it('fetches missing subtitles in bulk and leaves videos that already have one', function () {
    Storage::disk('videos')->put('Film/Film.mkv', 'x');
    Storage::disk('videos')->put('Done/Done.mkv', 'x');
    Storage::disk('videos')->put('Done/Done.fa.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nok\n");
    fakeServices([subsourceItem(3, 'x')], [3 => zipOf(['a.srt' => SRT_FA])], [['movieId' => 777, 'season' => null]]);

    $this->artisan('subtitles:fetch', ['--all' => true, '--pause' => 0])
        ->expectsOutputToContain('stored')
        ->assertSuccessful();

    Storage::disk('videos')->assertExists('Film/Film.fa.vtt');
});

it('treats an unreachable SubSource like any other service trouble, not a crash', function () {
    Storage::disk('videos')->put('Film/Film.mkv', 'x');
    config(['services.tmdb.key' => 't', 'services.subsource.key' => 'k']);
    resetHttp();
    Http::fake([
        'api.themoviedb.org/*' => Http::response(['results' => [['id' => 1]], 'imdb_id' => 'tt1']),
        'api.subsource.net/*' => fn () => throw new ConnectionException('cURL error 35: Connection was reset'),
    ]);

    expect(fn () => app(SubtitleFinder::class)->fetch('Film/Film.mkv'))->toThrow(RuntimeException::class, 'unreachable');
    expect(SubtitleLookup::count())->toBe(0);                                   // an outage is not a "no subtitle" result

    $this->withSession(['admin_auth' => true])->getJson('/subtitles/online?video=Film/Film.mkv')->assertStatus(502);
    $this->withSession(['admin_auth' => true])->postJson('/subtitles/online', ['video' => 'Film/Film.mkv'])->assertStatus(502);
});
