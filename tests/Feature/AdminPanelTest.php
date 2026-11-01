<?php

use App\Models\SubtitleLookup;
use App\Services\LibraryManager;
use App\Services\MediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToMoveFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
    ]);
    Storage::fake('videos');
    Storage::fake('hls');
    Cache::flush();
    RateLimiter::clear('admin-login:127.0.0.1');
});

function asAdmin()
{
    return test()->withSession(['admin_auth' => true]);
}

/** A converted video as the media pipeline leaves it: playlist next to the video, segments in the hls disk. */
function putHlsVideo(string $dir, string $base, string $key = 'abcdef0123456789'): void
{
    $prefix = $dir === '' ? '' : $dir.'/';
    Storage::disk('videos')->put($prefix.$base.'.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n/storage/hls/{$key}/p0.m3u8\n");
    Storage::disk('videos')->put($prefix.$base.'.fa.vtt', "WEBVTT\n");
    Storage::disk('hls')->put("{$key}/master.m3u8", '#EXTM3U');
    Storage::disk('hls')->put("{$key}/seg_0_00000.m4s", str_repeat('x', 100));
}

it('logs the admin in with a session and sends them to the page they wanted', function () {
    $this->get('/admin/library')->assertRedirect(route('admin.login'));

    $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret'])
        ->assertRedirect(route('admin.library'))
        ->assertSessionHas('admin_auth', true);

    $this->get('/admin')->assertOk()->assertSee('داشبورد');
});

it('rejects wrong credentials without revealing which one was wrong', function (string $user, string $password) {
    $this->post('/admin/login', ['username' => $user, 'password' => $password])
        ->assertSessionHasErrors('username')
        ->assertSessionMissing('admin_auth');

    $this->get('/admin')->assertRedirect(route('admin.login'));
})->with([
    'wrong password' => ['admin', 'nope'],
    'wrong user' => ['root', 'secret'],
]);

it('locks the login after repeated failures, even for the right password', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'bad'.$i])->assertSessionHasErrors('username');
    }

    // the lock message is shown on the form (a redirect back), and the right password no longer logs in
    $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret'])
        ->assertSessionHasErrors(['username' => 'تلاش‌های ناموفق زیاد بود. 300 ثانیه دیگر دوباره امتحان کنید.'])
        ->assertSessionMissing('admin_auth');
});

it('changes the session id on login and drops everything on logout', function () {
    $before = session()->getId();

    $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret']);
    expect(session()->getId())->not->toBe($before);

    $this->post('/admin/logout')->assertRedirect('/');
    $this->get('/admin')->assertRedirect(route('admin.login'));
});

it('shows a notice and refuses access when no admin account is configured', function () {
    config(['admin.user' => null, 'admin.password_hash' => null]);

    $this->get('/admin/login')->assertOk()->assertSee('تنظیم نشده');
    $this->post('/admin/login', ['username' => 'a', 'password' => 'b'])->assertStatus(503);
    asAdmin()->get('/admin')->assertStatus(503);
});

it('answers JSON clients with 401 instead of a redirect', function () {
    $this->getJson('/admin')->assertUnauthorized();
});

it('shows the admin menu only to a logged-in admin', function () {
    $this->get('/')->assertOk()->assertDontSee('مدیریت کتابخانه')->assertSee(route('admin.login'));
    asAdmin()->get('/')->assertOk()->assertSee('مدیریت کتابخانه');
});

it('renders the dashboard with library, queue and disk figures', function () {
    $disk = Storage::disk('videos');
    $disk->put('Show/S1/e1.mkv', 'x');                  // waiting for conversion
    $disk->put('Show/S1/e2.mp4', 'xx');
    $disk->put('Show/S1/e2.fa.vtt', "WEBVTT\n");
    putHlsVideo('Show/S1', 'e3');
    $disk->put('Show/S1/e3.mkv', 'original kept');       // superseded by e3.m3u8: not counted
    DB::table('jobs')->insert(['queue' => 'media', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

    $response = asAdmin()->get('/admin')->assertOk();

    expect($response->viewData('stats'))->toMatchArray(['titles' => 1, 'videos' => 3, 'hls' => 1, 'mp4' => 1, 'waiting' => 1, 'noSubtitle' => 1]);
    expect($response->viewData('queue'))->toMatchArray(['media' => 1, 'default' => 0, 'failed' => 0]);
    expect($response->viewData('disk')['total'])->toBeGreaterThan(0);
});

it('lists a folder with sidecars folded into the video row', function () {
    $disk = Storage::disk('videos');
    $disk->put('Show/e1.mkv', 'x');
    $disk->put('Show/e1.fa.vtt', "WEBVTT\n");
    $disk->put('Show/e1.en.srt', 'x');
    $disk->put('Show/_cover.jpg', 'img');
    $disk->makeDirectory('Show/S1');

    $entries = asAdmin()->get('/admin/library/Show')->assertOk()->assertSee('e1.mkv')->viewData('entries');

    expect(array_column($entries['dirs'], 'name'))->toBe(['S1'])
        ->and(array_column($entries['files'], 'name'))->toBe(['e1.mkv', '_cover.jpg'])
        ->and($entries['files'][0]['subtitles'])->toEqualCanonicalizing(['fa', 'en']);

    asAdmin()->get('/admin/library/Nope')->assertNotFound();
    asAdmin()->get('/admin/library/%2e%2e')->assertNotFound();
});

it('renames a video together with its subtitles, sibling formats and lookup record', function () {
    $disk = Storage::disk('videos');
    $disk->put('Show/e1.mkv', 'x');
    $disk->put('Show/e1.mp4', 'x');
    $disk->put('Show/e1.fa.vtt', "WEBVTT\n");
    $disk->put('Show/e10.mkv', 'other episode');         // shares a prefix but is a different video
    SubtitleLookup::create(['video_path' => 'Show/e1.mkv', 'lang' => 'fa', 'status' => 'found', 'subtitle_id' => 5, 'checked_at' => now()]);

    asAdmin()->post('/admin/rename', ['path' => 'Show/e1.mkv', 'name' => 'Pilot'])->assertSessionHas('success');

    foreach (['Show/Pilot.mkv', 'Show/Pilot.mp4', 'Show/Pilot.fa.vtt', 'Show/e10.mkv'] as $kept) {
        $disk->assertExists($kept);
    }
    foreach (['Show/e1.mkv', 'Show/e1.mp4', 'Show/e1.fa.vtt'] as $gone) {
        $disk->assertMissing($gone);
    }
    expect(SubtitleLookup::first()->video_path)->toBe('Show/Pilot.mkv');
});

it('keeps an HLS video playable after rename and move because its folder is found via the playlist', function () {
    putHlsVideo('Show', 'e1');

    asAdmin()->post('/admin/rename', ['path' => 'Show/e1.m3u8', 'name' => 'Pilot'])->assertSessionHas('success');
    Storage::disk('videos')->makeDirectory('Other');
    asAdmin()->post('/admin/move', ['path' => 'Show/Pilot.m3u8', 'destination' => 'Other'])->assertSessionHas('success');

    Storage::disk('videos')->assertExists('Other/Pilot.m3u8');
    Storage::disk('videos')->assertExists('Other/Pilot.fa.vtt');
    Storage::disk('hls')->assertExists('abcdef0123456789/seg_0_00000.m4s');
    expect(MediaProcessor::hlsKeyFromPlaylist(Storage::disk('videos')->get('Other/Pilot.m3u8')))->toBe('abcdef0123456789');
});

it('renames and moves folders and updates lookup records inside them', function () {
    $disk = Storage::disk('videos');
    $disk->put('Old/S1/e1.mkv', 'x');
    $disk->makeDirectory('Archive');
    SubtitleLookup::create(['video_path' => 'Old/S1/e1.mkv', 'lang' => 'fa', 'status' => 'none', 'checked_at' => now()]);

    asAdmin()->post('/admin/rename', ['path' => 'Old', 'name' => 'New'])->assertSessionHas('success');
    $disk->assertExists('New/S1/e1.mkv');
    expect(SubtitleLookup::first()->video_path)->toBe('New/S1/e1.mkv');

    asAdmin()->post('/admin/move', ['path' => 'New', 'destination' => 'Archive'])->assertSessionHas('success');
    $disk->assertExists('Archive/New/S1/e1.mkv');
    expect(SubtitleLookup::first()->video_path)->toBe('Archive/New/S1/e1.mkv');
});

it('refuses conflicting, circular and out-of-library operations', function (string $route, array $input) {
    $disk = Storage::disk('videos');
    $disk->put('Show/e1.mkv', 'x');
    $disk->put('Show/e2.mkv', 'y');
    $disk->makeDirectory('Show/Sub');

    asAdmin()->post($route, $input)->assertSessionHas('error');

    $disk->assertExists('Show/e1.mkv');
    $disk->assertExists('Show/e2.mkv');
})->with([
    'rename onto existing file' => ['/admin/rename', ['path' => 'Show/e1.mkv', 'name' => 'e2']],
    'rename with a slash' => ['/admin/rename', ['path' => 'Show/e1.mkv', 'name' => 'a/b']],
    'rename with traversal' => ['/admin/rename', ['path' => 'Show/e1.mkv', 'name' => '..']],
    'rename a missing path' => ['/admin/rename', ['path' => 'Show/none.mkv', 'name' => 'x']],
    'move folder into itself' => ['/admin/move', ['path' => 'Show', 'destination' => 'Show/Sub']],
    'move to a missing folder' => ['/admin/move', ['path' => 'Show/e1.mkv', 'destination' => 'Nowhere']],
    'move out via traversal' => ['/admin/move', ['path' => 'Show/e1.mkv', 'destination' => '../..']],
    'delete the library root' => ['/admin/delete', ['path' => '/']],
    'delete via traversal' => ['/admin/delete', ['path' => '../../.env']],
]);

it('deletes a video with its subtitles, HLS segments and lookup record, leaving neighbours alone', function () {
    putHlsVideo('Show', 'e1');
    putHlsVideo('Show', 'e2', '1111111111111111');
    SubtitleLookup::create(['video_path' => 'Show/e1.m3u8', 'lang' => 'fa', 'status' => 'found', 'checked_at' => now()]);

    asAdmin()->post('/admin/delete', ['path' => 'Show/e1.m3u8'])->assertSessionHas('success');

    Storage::disk('videos')->assertMissing('Show/e1.m3u8');
    Storage::disk('videos')->assertMissing('Show/e1.fa.vtt');
    Storage::disk('hls')->assertMissing('abcdef0123456789/seg_0_00000.m4s');
    Storage::disk('videos')->assertExists('Show/e2.m3u8');
    Storage::disk('hls')->assertExists('1111111111111111/seg_0_00000.m4s');
    expect(SubtitleLookup::count())->toBe(0);
});

it('deletes a folder including the HLS segments of every video inside', function () {
    putHlsVideo('Show/S1', 'e1');
    Storage::disk('videos')->put('Keep/e1.mkv', 'x');
    SubtitleLookup::create(['video_path' => 'Show/S1/e1.m3u8', 'lang' => 'fa', 'status' => 'none', 'checked_at' => now()]);
    SubtitleLookup::create(['video_path' => 'Keep/e1.mkv', 'lang' => 'fa', 'status' => 'none', 'checked_at' => now()]);

    asAdmin()->post('/admin/delete', ['path' => 'Show'])->assertSessionHas('success');

    Storage::disk('videos')->assertMissing('Show');
    Storage::disk('hls')->assertMissing('abcdef0123456789/seg_0_00000.m4s');
    Storage::disk('videos')->assertExists('Keep/e1.mkv');
    expect(SubtitleLookup::pluck('video_path')->all())->toBe(['Keep/e1.mkv']);
});

it('only lets the admin use the management actions', function (string $uri) {
    Storage::disk('videos')->put('Show/e1.mkv', 'x');

    $this->post($uri, ['path' => 'Show/e1.mkv', 'name' => 'x', 'destination' => ''])->assertRedirect(route('admin.login'));
    Storage::disk('videos')->assertExists('Show/e1.mkv');
})->with(['/admin/rename', '/admin/move', '/admin/delete']);

it('is usable through the service directly', function () {
    Storage::disk('videos')->put('a.mp4', 'x');

    expect(app(LibraryManager::class)->rename('a.mp4', 'b'))->toBe('b.mp4');
});

it('still completes file operations when the lookup database is unavailable', function () {
    Storage::disk('videos')->put('Show/e1.mkv', 'x');
    Schema::drop('subtitle_lookups');

    asAdmin()->post('/admin/rename', ['path' => 'Show/e1.mkv', 'name' => 'Pilot'])->assertSessionHas('success');
    asAdmin()->post('/admin/delete', ['path' => 'Show/Pilot.mkv'])->assertSessionHas('success');

    Storage::disk('videos')->assertMissing('Show/Pilot.mkv');
});

it('raises on failed file operations instead of silently returning false', function () {
    expect(config('filesystems.disks.videos.throw'))->toBeTrue()
        ->and(config('filesystems.disks.hls.throw'))->toBeTrue();
});

it('reports a rename that the filesystem refused instead of claiming success or crashing', function () {
    Storage::disk('videos')->put('Show/e1.mkv', 'x');
    $manager = Mockery::mock(LibraryManager::class);
    $manager->shouldReceive('rename')->andThrow(UnableToMoveFile::fromLocationTo('Show/e1.mkv', 'Show/Pilot.mkv'));
    app()->instance(LibraryManager::class, $manager);

    asAdmin()->post('/admin/rename', ['path' => 'Show/e1.mkv', 'name' => 'Pilot'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'اجازه‌ی نوشتن'))
        ->assertSessionMissing('success');
});
