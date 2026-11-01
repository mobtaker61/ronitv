<?php

use App\Models\SubtitleLookup;
use App\Models\Title;
use App\Services\LibraryManager;
use App\Services\SubtitleFinder;
use App\Services\TitleCatalog;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
        'services.tmdb.key' => 't',
        'services.subsource.key' => 'k',
    ]);
    Storage::fake('videos');
    Cache::flush();
    Http::swap(new Factory);
});

function tmdbPayload(int $id, string $title, int $year, array $extra = []): array
{
    return $extra + [
        'id' => $id, 'title' => $title, 'original_title' => $title, 'release_date' => "{$year}-06-01",
        'overview' => "About {$title} {$year}", 'poster_path' => "/p{$id}.jpg", 'vote_average' => 7.4, 'imdb_id' => "tt{$id}",
        'genres' => [['id' => 1, 'name' => 'Drama']],
        'credits' => ['cast' => array_map(fn ($i) => ['name' => "Actor {$i}", 'character' => 'x', 'profile_path' => null, 'order' => $i], range(1, 30)), 'crew' => [['name' => 'Big crew list']]],
    ];
}

function fakeTmdb(): void
{
    Http::fake([
        // the same name exists as two films and a series
        'api.themoviedb.org/3/search/movie*' => Http::response(['results' => [
            ['id' => 11, 'title' => 'Wild', 'original_title' => 'Wild', 'release_date' => '2014-12-05', 'poster_path' => '/w14.jpg', 'popularity' => 30, 'vote_average' => 7.1, 'overview' => 'Cheryl hikes the trail.'],
            ['id' => 12, 'title' => 'Wild', 'original_title' => 'Wild', 'release_date' => '1992-01-01', 'poster_path' => null, 'popularity' => 5, 'vote_average' => 5.0, 'overview' => ''],
        ]]),
        'api.themoviedb.org/3/search/tv*' => Http::response(['results' => [
            ['id' => 21, 'name' => 'Wild', 'original_name' => 'Wild', 'first_air_date' => '2008-03-01', 'poster_path' => '/wtv.jpg', 'popularity' => 12, 'vote_average' => 6.0, 'overview' => 'A series.'],
        ]]),
        'api.themoviedb.org/3/movie/11*' => Http::response(tmdbPayload(11, 'Wild', 2014)),
        'api.themoviedb.org/3/movie/12*' => Http::response(tmdbPayload(12, 'Wild', 1992)),
        'api.themoviedb.org/3/tv/21*' => Http::response(['id' => 21, 'name' => 'Wild', 'original_name' => 'Wild', 'first_air_date' => '2008-03-01', 'overview' => '', 'poster_path' => '/wtv.jpg', 'external_ids' => ['imdb_id' => 'tt0021']]),
    ]);
}

function adminSession()
{
    return test()->withSession(['admin_auth' => true]);
}

it('lists every movie and series that shares the name, most popular first, with the year found in the file names', function () {
    fakeTmdb();
    Storage::disk('videos')->put('Wild/Wild.2014.720p.mkv', 'x');

    $response = adminSession()->getJson('/admin/titles/search?folder=Wild')->assertOk();

    expect($response->json('query'))->toBe('Wild')
        ->and($response->json('year'))->toBe(2014)                // taken from "Wild.2014.720p.mkv"
        ->and(array_column($response->json('candidates'), 'tmdb_id'))->toBe([11, 21, 12])   // 2014 film first, then by popularity
        ->and($response->json('candidates.1'))->toMatchArray(['type' => 'tv', 'title' => 'Wild', 'year' => 2008]);
});

it('searches with the query and year the admin typed, not the folder name', function () {
    fakeTmdb();
    Storage::disk('videos')->put('weird_folder/a.mkv', 'x');

    adminSession()->getJson('/admin/titles/search?folder=weird_folder&q=Wild&year=1992&type=movie')->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'search/movie') && str_contains($r->url(), 'query=Wild') && str_contains($r->url(), 'year=1992'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'search/tv'));
});

it('stores the chosen title with its full data once and shows it without calling TMDb again', function () {
    fakeTmdb();
    Storage::disk('videos')->put('Wild/Wild.2014.720p.mkv', 'x');

    adminSession()->postJson('/admin/titles/assign', ['folder' => 'Wild', 'tmdb_id' => 11, 'type' => 'movie'])
        ->assertOk()->assertJsonPath('ok', true);

    $title = Title::where('folder', 'Wild')->first();
    expect($title)->tmdb_id->toBe(11)->media_type->toBe('movie')->matched_by->toBe('manual')
        ->title->toBe('Wild')->year->toBe(2014)->poster_path->toBe('/p11.jpg')->imdb_id->toBe('tt11');
    expect($title->details['credits']['cast'])->toHaveCount(12)                // trimmed
        ->and($title->details)->not->toHaveKey('credits.crew');

    $before = count(Http::recorded());
    $this->get('/folder/Wild')->assertOk()->assertSee('About Wild 2014')->assertSee('/p11.jpg');
    $this->get('/')->assertOk()->assertSee('/p11.jpg');
    expect(count(Http::recorded()))->toBe($before);                              // nothing fetched for display
});

it('falls back to the English overview when the Persian one is empty', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/21*' => Http::sequence()
            ->push(['id' => 21, 'name' => 'Wild', 'first_air_date' => '2008-03-01', 'overview' => '', 'external_ids' => ['imdb_id' => 'tt0021']])
            ->push(['overview' => 'English summary']),
    ]);
    Storage::disk('videos')->makeDirectory('Wild');

    app(TitleCatalog::class)->assign('Wild', 21, 'tv');

    expect(Title::first()->details)->toMatchArray(['overview' => 'English summary', 'overview_language' => 'en']);
});

it('accepts a themoviedb.org link or movie/id and rejects nonsense', function (string $reference, bool $ok) {
    fakeTmdb();
    Storage::disk('videos')->makeDirectory('Wild');

    $response = adminSession()->postJson('/admin/titles/assign', ['folder' => 'Wild', 'reference' => $reference]);

    $ok ? $response->assertOk() : $response->assertStatus(422);
    expect(Title::count())->toBe($ok ? 1 : 0);
})->with([
    'full link' => ['https://www.themoviedb.org/movie/12-wild', true],
    'type and id' => ['movie/11', true],
    'tv link' => ['https://www.themoviedb.org/tv/21', true],
    'bare text' => ['hello', false],
    'person page' => ['https://www.themoviedb.org/person/5', false],
]);

it('reports a TMDb id it cannot fetch and keeps the old link', function () {
    fakeTmdb();
    Storage::disk('videos')->makeDirectory('Wild');
    app(TitleCatalog::class)->assign('Wild', 11, 'movie');
    Http::swap(new Factory);
    Http::fake(['api.themoviedb.org/*' => Http::response(['status_message' => 'not found'], 404)]);

    adminSession()->postJson('/admin/titles/assign', ['folder' => 'Wild', 'tmdb_id' => 999, 'type' => 'movie'])->assertStatus(422);

    expect(Title::first()->tmdb_id)->toBe(11);
});

it('replaces an automatic guess and clears the subtitle misses that belonged to the wrong film', function () {
    fakeTmdb();
    Storage::disk('videos')->put('Wild/Wild.mkv', 'x');
    app(TitleCatalog::class)->assign('Wild', 12, 'movie', Title::AUTO);
    SubtitleLookup::create(['video_path' => 'Wild/Wild.mkv', 'lang' => 'fa', 'status' => 'none', 'checked_at' => now()]);
    SubtitleLookup::create(['video_path' => 'Wild/Other.mkv', 'lang' => 'fa', 'status' => 'found', 'subtitle_id' => 5, 'checked_at' => now()]);

    adminSession()->postJson('/admin/titles/assign', ['folder' => 'Wild', 'tmdb_id' => 11, 'type' => 'movie'])->assertOk();

    expect(Title::first())->tmdb_id->toBe(11)->matched_by->toBe('manual')
        ->and(SubtitleLookup::pluck('video_path')->all())->toBe(['Wild/Other.mkv']);   // the miss is gone, the hit stays
});

it('refreshes from TMDb and resets the link on request', function () {
    fakeTmdb();
    Storage::disk('videos')->makeDirectory('Wild');
    app(TitleCatalog::class)->assign('Wild', 11, 'movie');

    Http::swap(new Factory);
    Http::fake(['api.themoviedb.org/3/movie/11*' => Http::response(tmdbPayload(11, 'Wild (new title)', 2014))]);
    adminSession()->postJson('/admin/titles/refresh', ['folder' => 'Wild'])->assertOk();
    expect(Title::first()->title)->toBe('Wild (new title)');

    adminSession()->postJson('/admin/titles/reset', ['folder' => 'Wild'])->assertOk();
    expect(Title::count())->toBe(0);
});

it('only lets the admin choose titles, and only for real top-level folders', function () {
    Storage::disk('videos')->put('Show/S1/e1.mkv', 'x');

    $this->getJson('/admin/titles/search?folder=Show')->assertUnauthorized();
    $this->postJson('/admin/titles/assign', ['folder' => 'Show', 'tmdb_id' => 1, 'type' => 'movie'])->assertUnauthorized();

    adminSession()->getJson('/admin/titles/search?folder=Nope')->assertNotFound();
    adminSession()->getJson('/admin/titles/search?folder=Show/S1')->assertNotFound();      // not top-level
    adminSession()->getJson('/admin/titles/search?folder=..')->assertNotFound();
});

it('tells the admin when TMDb cannot be reached', function () {
    Http::fake(['api.themoviedb.org/*' => Http::response('down', 500)]);
    Storage::disk('videos')->makeDirectory('Wild');

    adminSession()->getJson('/admin/titles/search?folder=Wild')->assertStatus(502);
});

it('guesses a new folder once, stores the guess as automatic, and remembers a miss for a week', function () {
    fakeTmdb();
    Storage::disk('videos')->put('Wild/Wild.mkv', 'x');

    expect(app(TitleCatalog::class)->forFolder('Wild', 'movie'))->tmdb_id->toBe(11)->matched_by->toBe('auto');

    // a folder TMDb has nothing for
    Http::swap(new Factory);
    Http::fake(['api.themoviedb.org/3/search/*' => Http::response(['results' => []])]);
    Storage::disk('videos')->put('بی_داد/a.mkv', 'x');

    expect(app(TitleCatalog::class)->forFolder('بی_داد', 'movie'))->toBeNull();
    expect(Title::where('folder', 'بی_داد')->first())->tmdb_id->toBeNull();

    $sent = count(Http::recorded());
    app(TitleCatalog::class)->forFolder('بی_داد', 'movie');
    expect(count(Http::recorded()))->toBe($sent);                                   // not asked again within the week

    Title::where('folder', 'بی_داد')->update(['fetched_at' => now()->subDays(8)]);
    app(TitleCatalog::class)->forFolder('بی_داد', 'movie');
    expect(count(Http::recorded()))->toBeGreaterThan($sent);                         // retried after the week
});

it('stores nothing when TMDb is down, so the next visit tries again', function () {
    Http::fake(['api.themoviedb.org/*' => Http::response('down', 500)]);
    Storage::disk('videos')->put('Wild/Wild.mkv', 'x');

    expect(app(TitleCatalog::class)->forFolder('Wild', 'movie'))->toBeNull();
    expect(Title::count())->toBe(0);
});

it('keeps pages working when the titles table is unavailable', function () {
    Storage::disk('videos')->put('Wild/Wild.mp4', 'x');
    Schema::drop('titles');

    $this->get('/')->assertOk()->assertSee('Wild');
    $this->get('/folder/Wild')->assertOk();
});

it('follows a top-level folder through rename, move and delete', function () {
    Storage::disk('videos')->put('Old/a.mkv', 'x');
    Storage::disk('videos')->makeDirectory('Archive');
    Title::create(['folder' => 'Old', 'tmdb_id' => 5, 'media_type' => 'movie', 'matched_by' => 'manual', 'title' => 'Old']);
    $library = app(LibraryManager::class);

    $library->rename('Old', 'New');
    expect(Title::pluck('folder')->all())->toBe(['New']);

    $library->move('New', 'Archive');                      // no longer top-level: no longer a title
    expect(Title::count())->toBe(0);

    Title::create(['folder' => 'Gone', 'tmdb_id' => 6, 'media_type' => 'tv', 'matched_by' => 'auto']);
    Storage::disk('videos')->put('Gone/a.mkv', 'x');
    $library->delete('Gone');
    expect(Title::count())->toBe(0);
});

it('uses the stored IMDb id for the subtitle search instead of searching TMDb by name', function () {
    Storage::disk('videos')->put('Wild/Wild.2014.mkv', 'x');
    Title::create(['folder' => 'Wild', 'tmdb_id' => 11, 'media_type' => 'movie', 'matched_by' => 'manual', 'title' => 'Wild', 'original_title' => 'Wild', 'year' => 2014, 'imdb_id' => 'tt2305051']);
    Http::fake([
        'api.subsource.net/api/v1/movies/search*' => Http::response(['data' => [['movieId' => 700, 'season' => null]]]),
        'api.subsource.net/api/v1/subtitles?*' => Http::response(['data' => []]),
    ]);

    app(SubtitleFinder::class)->candidates('Wild/Wild.2014.mkv');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'movies/search') && str_contains($r->url(), 'imdb=tt2305051'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'themoviedb.org'));
});

it('puts the title released in the requested year first, even when another is more popular', function () {
    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response(['results' => [
            ['id' => 1, 'title' => 'Wild', 'release_date' => '2007-01-01', 'popularity' => 90],
            ['id' => 2, 'title' => 'Wild', 'release_date' => '2014-01-01', 'popularity' => 10],
        ]]),
        'api.themoviedb.org/3/search/tv*' => Http::response(['results' => []]),
    ]);

    $ids = array_column(app(TmdbService::class)->searchCandidates('Wild', null, 2014), 'tmdb_id');

    expect($ids)->toBe([2, 1]);
});
