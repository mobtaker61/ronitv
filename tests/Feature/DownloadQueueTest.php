<?php

use App\Jobs\DownloadRemoteVideo;
use App\Services\RemoteDownloader;
use App\Support\DownloadStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
    ]);
    Storage::fake('videos');
    Cache::flush();
});

function admin(): array
{
    return ['Authorization' => 'Basic '.base64_encode('admin:secret')];
}

it('queues a download instead of fetching inline', function () {
    Queue::fake();
    Http::fake();

    $this->withHeaders(admin())
        ->post('/download-url', ['url' => 'http://93.184.216.34/movie.mp4', 'folder' => 'Movie', 'filename' => 'm.mp4'])
        ->assertSessionHas('success');

    Queue::assertPushed(DownloadRemoteVideo::class, fn ($job) => $job->relativePath === 'Movie/m.mp4');
    Http::assertNothingSent();

    $this->withHeaders(admin())->getJson('/download-status')
        ->assertOk()
        ->assertJsonPath('0.status', 'queued')
        ->assertJsonPath('0.path', 'Movie/m.mp4');
});

it('does not queue invalid, non-video or duplicate downloads', function (array $input) {
    Queue::fake();
    Storage::disk('videos')->put('Movie/exists.mp4', 'x');

    $this->withHeaders(admin())
        ->post('/download-url', $input + ['folder' => 'Movie'])
        ->assertSessionHas('error');

    Queue::assertNothingPushed();
})->with([
    'private ip' => [['url' => 'http://10.0.0.1/a.mp4']],
    'not a video' => [['url' => 'http://93.184.216.34/a.php']],
    'already exists' => [['url' => 'http://93.184.216.34/exists.mp4']],
    'traversal' => [['url' => 'http://93.184.216.34/a.mp4', 'filename' => '../a.mp4']],
]);

it('requires admin for the status endpoint', function () {
    $this->getJson('/download-status')->assertUnauthorized();
});

it('downloads in the job, tracks progress and moves the file into place', function () {
    DownloadStatus::create('abc', 'Movie/m.mp4');

    $downloader = Mockery::mock(RemoteDownloader::class);
    $downloader->shouldReceive('download')->once()->andReturnUsing(function ($url, $dest, $onProgress) {
        file_put_contents($dest, 'video-bytes');
        $onProgress(5, 11);
    });

    (new DownloadRemoteVideo('abc', 'http://93.184.216.34/m.mp4', 'Movie/m.mp4'))->handle($downloader);

    Storage::disk('videos')->assertExists('Movie/m.mp4');
    Storage::disk('videos')->assertMissing('Movie/m.mp4.part');
    expect(DownloadStatus::recent()[0])->toMatchArray(['status' => 'done', 'downloaded' => 11, 'total' => 11]);
});

it('marks the job failed and removes the partial file on errors', function () {
    DownloadStatus::create('abc', 'Movie/m.mp4');

    $downloader = Mockery::mock(RemoteDownloader::class);
    $downloader->shouldReceive('download')->andReturnUsing(function ($url, $dest) {
        file_put_contents($dest, 'half');
        throw new RuntimeException('boom');
    });

    (new DownloadRemoteVideo('abc', 'http://93.184.216.34/m.mp4', 'Movie/m.mp4'))->handle($downloader);

    Storage::disk('videos')->assertMissing('Movie/m.mp4');
    Storage::disk('videos')->assertMissing('Movie/m.mp4.part');
    expect(DownloadStatus::recent()[0])->toMatchArray(['status' => 'failed', 'error' => 'دانلود ناموفق بود.']);
});

it('never overwrites an existing file in the job', function () {
    Storage::disk('videos')->put('Movie/m.mp4', 'original');
    DownloadStatus::create('abc', 'Movie/m.mp4');

    $downloader = Mockery::mock(RemoteDownloader::class);
    $downloader->shouldNotReceive('download');

    (new DownloadRemoteVideo('abc', 'http://93.184.216.34/m.mp4', 'Movie/m.mp4'))->handle($downloader);

    expect(Storage::disk('videos')->get('Movie/m.mp4'))->toBe('original')
        ->and(DownloadStatus::recent()[0]['status'])->toBe('failed');
});

it('shows the compact TMDb header for a movie folder', function () {
    config(['services.tmdb.key' => 'test']);
    Storage::disk('videos')->put('Heat/heat.mp4', 'x');
    Http::fake([
        'api.themoviedb.org/3/search/movie*' => Http::response(['results' => [['id' => 7]]]),
        'api.themoviedb.org/3/movie/7*' => Http::response([
            'title' => 'Heat', 'overview' => 'A cop and a thief.', 'vote_average' => 8.2,
            'release_date' => '1995-12-15', 'runtime' => 170, 'poster_path' => '/h.jpg',
            'genres' => [['name' => 'Crime']], 'credits' => ['cast' => [['name' => 'Al Pacino']]],
        ]),
    ]);

    $this->get('/folder/Heat')
        ->assertOk()
        ->assertSee('<div class="tmdb-header', false)
        ->assertSee('A cop and a thief.')
        ->assertSee('170 دقیقه')
        ->assertSee('Al Pacino');
});

it('does not look up TMDb for season folders', function () {
    config(['services.tmdb.key' => 'test']);
    Storage::disk('videos')->put('Show/S1/e1.mp4', 'x');
    Http::fake(['api.themoviedb.org/*' => Http::response(['results' => []])]);

    $this->get('/folder/Show/S1')->assertOk()->assertDontSee('<div class="tmdb-header', false);

    Http::assertNothingSent();
});
