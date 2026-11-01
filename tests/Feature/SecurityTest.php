<?php

use App\Support\SafePath;
use App\Support\UrlGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function adminHeaders(string $user = 'admin', string $password = 'secret'): array
{
    return ['Authorization' => 'Basic '.base64_encode("$user:$password")];
}

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
    ]);
    Storage::fake('videos');
    Cache::flush();
});

it('rejects management routes without credentials', function (string $method, string $uri) {
    $this->call($method, $uri)->assertStatus(401);
})->with([
    ['GET', '/upload'],
    ['POST', '/upload'],
    ['POST', '/create-folder'],
    ['POST', '/download-url'],
]);

it('rejects wrong credentials', function () {
    $this->get('/upload', adminHeaders('admin', 'nope'))->assertStatus(401);
    $this->get('/upload', adminHeaders('root', 'secret'))->assertStatus(401);
});

it('fails closed when the admin account is not configured', function () {
    config(['admin.user' => null, 'admin.password_hash' => null]);

    $this->get('/upload', adminHeaders())->assertStatus(503);
});

it('shows the upload form to the admin', function () {
    Storage::disk('videos')->makeDirectory('Movie');

    $this->get('/upload', adminHeaders())->assertOk()->assertSee('Movie');
});

it('uploads a video for the admin', function () {
    $this->withHeaders(adminHeaders())
        ->post('/upload', [
            'folder' => 'Movie',
            'video' => UploadedFile::fake()->create('film.mp4', 100, 'video/mp4'),
        ])->assertSessionHas('success');

    Storage::disk('videos')->assertExists('Movie/film.mp4');
});

it('rejects non-video uploads and traversal in the folder', function () {
    $this->withHeaders(adminHeaders())
        ->post('/upload', [
            'folder' => 'Movie',
            'video' => UploadedFile::fake()->create('shell.php', 1),
        ])->assertSessionHasErrors('video');

    $this->withHeaders(adminHeaders())
        ->post('/upload', [
            'folder' => '../../public',
            'video' => UploadedFile::fake()->create('film.mp4', 100, 'video/mp4'),
        ])->assertSessionHas('error');

    Storage::disk('videos')->assertMissing('Movie/shell.php');
});

it('refuses to create folders outside the videos root', function () {
    $this->withHeaders(adminHeaders())
        ->post('/create-folder', ['folder_name' => '..', 'parent_folder' => ''])
        ->assertSessionHas('error');

    $this->withHeaders(adminHeaders())
        ->post('/create-folder', ['folder_name' => 'ok', 'parent_folder' => 'a/../..'])
        ->assertSessionHas('error');
});

it('blocks SSRF attempts on the download endpoint', function (string $url) {
    Http::fake();

    $this->withHeaders(adminHeaders())
        ->post('/download-url', ['url' => $url, 'folder' => 'Movie', 'filename' => 'x.mp4'])
        ->assertSessionHas('error');

    Http::assertNothingSent();
    Storage::disk('videos')->assertMissing('Movie/x.mp4');
})->with([
    'loopback' => 'http://127.0.0.1/secret.mp4',
    'metadata' => 'http://169.254.169.254/latest/meta-data.mp4',
    'private' => 'http://10.0.0.5/file.mp4',
    'odd port' => 'http://93.184.216.34:6379/file.mp4',
]);

it('only allows public http(s) URLs in UrlGuard', function () {
    expect(UrlGuard::resolvePublic('https://8.8.8.8/video.mp4'))
        ->toMatchArray(['host' => '8.8.8.8', 'port' => 443, 'ip' => '8.8.8.8']);

    foreach (['file:///etc/passwd', 'ftp://8.8.8.8/a.mp4', 'http://user:pw@8.8.8.8/a.mp4', 'http://[::1]/a.mp4', 'http://192.168.1.1/a.mp4'] as $bad) {
        expect(fn () => UrlGuard::resolvePublic($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('only accepts clean relative paths', function () {
    expect(SafePath::clean('/Series/Season 1/'))->toBe('Series/Season 1')
        ->and(SafePath::clean(null))->toBe('')
        ->and(SafePath::clean('سریال/فصل ۱'))->toBe('سریال/فصل ۱');

    foreach (['../x', 'a/../b', 'a//b', './a', "a\0b", 'a\\b'] as $bad) {
        expect(fn () => SafePath::clean($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('404s on traversal and unknown folders', function () {
    $this->get('/folder/%2e%2e/%2e%2e')->assertNotFound();
    $this->get('/folder/does-not-exist')->assertNotFound();
    $this->get('/ajax/season-videos/..%2F/x')->assertNotFound();
});

it('lists season videos as json', function () {
    Storage::disk('videos')->put('Show/S1/e1.mp4', 'x');
    Storage::disk('videos')->put('Show/S1/notes.txt', 'x');

    $this->get('/ajax/season-videos/Show/S1')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'e1.mp4');
});

it('keeps the home page up when TMDb is down', function () {
    config(['services.tmdb.key' => 'test']);
    Storage::disk('videos')->put('Movie/film.mp4', 'x');
    Http::fake(['api.themoviedb.org/*' => Http::response('boom', 500)]);

    $this->get('/')->assertOk()->assertSee('Movie');
});

it('caches TMDb lookups', function () {
    config(['services.tmdb.key' => 'test']);
    Storage::disk('videos')->put('Movie/film.mp4', 'x');
    Http::fake(['api.themoviedb.org/*' => Http::response(['results' => [['id' => 1, 'poster_path' => '/p.jpg']]])]);

    $this->get('/')->assertOk()->assertSee('/p.jpg');
    $this->get('/')->assertOk();

    Http::assertSentCount(1);
});
