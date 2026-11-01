<?php

use App\Http\Controllers\VideoController;
use App\Jobs\ProcessVideo;
use App\Services\Ffmpeg;
use App\Services\MediaProcessor;
use App\Services\VideoLibrary;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function ffmpegAvailable(): bool
{
    static $available;

    return $available ??= Process::run([config('media.ffmpeg'), '-version'])->successful()
        && Process::run([config('media.ffprobe'), '-version'])->successful();
}

/**
 * Build a tiny MKV with the given audio languages and subtitle languages.
 *
 * @param  list<string>  $audio
 * @param  array<string, string>  $subs  language => cue text
 */
function makeMkv(string $relative, array $audio, array $subs = []): void
{
    $disk = Storage::disk('videos');
    $disk->makeDirectory(dirname($relative) === '.' ? '' : dirname($relative));

    $inputs = ['-f', 'lavfi', '-i', 'testsrc=d=8:s=320x180:r=25'];
    $maps = ['-map', '0:v'];
    foreach ($audio as $i => $lang) {
        array_push($inputs, '-f', 'lavfi', '-i', 'sine=f='.(300 + $i * 200).':d=8');
        array_push($maps, '-map', ($i + 1).':a');
    }

    $meta = [];
    foreach (array_values($audio) as $i => $lang) {
        array_push($meta, "-metadata:s:a:$i", "language=$lang");
    }

    $n = count($audio) + 1;
    $j = 0;
    foreach ($subs as $lang => $text) {
        $srt = tempnam(sys_get_temp_dir(), 'srt').'.srt';
        file_put_contents($srt, "1\n00:00:01,000 --> 00:00:03,000\n$text\n\n2\n00:00:04,000 --> 00:00:06,000\n$text 2\n");
        array_push($inputs, '-i', $srt);
        array_push($maps, '-map', ($n++).':0');
        array_push($meta, "-metadata:s:s:$j", "language=$lang");
        $j++;
    }

    $result = Process::run([config('media.ffmpeg'), '-v', 'error', '-y', ...$inputs, ...$maps,
        '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'libmp3lame', '-c:s', 'srt', ...$meta, $disk->path($relative)]);

    expect($result->successful())->toBeTrue($result->errorOutput());
}

beforeEach(function () {
    config(['media.min_subtitle_cues' => 1]);
    Storage::fake('videos');
    Storage::fake('hls');
});

it('lists the playlist and hides the original it replaced', function () {
    $disk = Storage::disk('videos');
    foreach (['Show/e1.mkv', 'Show/e1.mp4', 'Show/e1.m3u8', 'Show/e2.mkv', 'Show/e2.mp4', 'Show/e3.mkv'] as $f) {
        $disk->put($f, 'x');
    }

    $videos = app(VideoLibrary::class)->videos($disk, 'Show');

    expect(array_column($videos, 'name'))->toBe(['e1.m3u8', 'e2.mp4', 'e3.mkv'])
        ->and($videos[0]['type'])->toBe('application/x-mpegURL')
        ->and($videos[0]['download'])->toContain('/download/Show/e1.m3u8')
        ->and($videos[1]['type'])->toBe('video/mp4')
        ->and($videos[1]['download'])->toContain('/storage/videos/Show/e2.mp4');
});

it('never accepts playlists through upload or link download', function () {
    expect(VideoController::VIDEO_EXTENSIONS)->not->toContain('m3u8')
        ->and(VideoLibrary::VIDEO_EXTENSIONS)->toContain('m3u8');
});

it('serves 404 for downloads that are not converted playlists', function () {
    Storage::disk('videos')->put('Show/e1.mkv', 'x');
    Storage::disk('videos')->put('Show/e2.m3u8', '#EXTM3U');

    $this->get('/download/Show/e1.mkv')->assertNotFound();
    $this->get('/download/Show/e2.m3u8')->assertNotFound();      // playlist without segments
    $this->get('/download/Show/none.m3u8')->assertNotFound();
    $this->get('/download/%2e%2e/x.m3u8')->assertNotFound();
});

it('queues conversion on the media queue', function () {
    Queue::fake();

    ProcessVideo::dispatch('Show/e1.mkv', true);

    Queue::assertPushedOn('media', ProcessVideo::class, fn ($job) => $job->deleteOriginal === true);
});

it('converts a single-audio mkv to a faststart mp4 and extracts the subtitle', function () {
    makeMkv('Show/e1.mkv', ['per'], ['per' => 'سلام دنیا']);

    $result = app(MediaProcessor::class)->process('Show/e1.mkv');

    expect($result['mode'])->toBe('mp4')
        ->and($result['subtitles'])->toBe(['fa'])
        ->and($result['deleted'])->toBeFalse();

    $disk = Storage::disk('videos');
    $disk->assertExists('Show/e1.mkv');            // original kept without --delete-original
    $disk->assertExists('Show/e1.mp4');
    $disk->assertExists('Show/e1.fa.vtt');
    expect($disk->get('Show/e1.fa.vtt'))->toStartWith('WEBVTT')->toContain('سلام دنیا');
})->skip(fn () => ! ffmpegAvailable(), 'ffmpeg is not installed');

it('converts a two-audio mkv to HLS with a labelled audio rendition per language', function () {
    makeMkv('Show/e2.mkv', ['per', 'eng']);

    $result = app(MediaProcessor::class)->process('Show/e2.mkv', deleteOriginal: true);

    expect($result)->toMatchArray(['mode' => 'hls', 'deleted' => true]);

    $disk = Storage::disk('videos');
    $disk->assertMissing('Show/e2.mkv');
    $disk->assertExists('Show/e2.m3u8');

    $master = $disk->get('Show/e2.m3u8');
    $key = MediaProcessor::hlsKey('Show', 'e2');
    expect($master)->toContain('NAME="فارسی"')->toContain('LANGUAGE="fa"')->toContain('NAME="English"')
        ->and($master)->toContain("/storage/hls/{$key}/p0.m3u8")
        ->and(preg_match('/NAME="فارسی",DEFAULT=YES/', $master))->toBe(1);

    Storage::disk('hls')->assertExists("{$key}/master.m3u8");
    Storage::disk('hls')->assertExists("{$key}/init_0.mp4");

    // the library now offers exactly one playable item for it
    expect(array_column(app(VideoLibrary::class)->videos($disk, 'Show'), 'name'))->toBe(['e2.m3u8']);
})->skip(fn () => ! ffmpegAvailable(), 'ffmpeg is not installed');

it('does not overwrite an existing subtitle and refuses missing files', function () {
    makeMkv('Show/e3.mkv', ['per'], ['per' => 'from the file']);
    Storage::disk('videos')->put('Show/e3.fa.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nkeep me\n");

    $result = app(MediaProcessor::class)->process('Show/e3.mkv');

    expect($result['subtitles'])->toBe([])
        ->and(Storage::disk('videos')->get('Show/e3.fa.vtt'))->toContain('keep me');

    expect(fn () => app(MediaProcessor::class)->process('Show/missing.mkv'))->toThrow(RuntimeException::class);
    expect(fn () => app(MediaProcessor::class)->process('../etc/passwd'))->toThrow(InvalidArgumentException::class);
})->skip(fn () => ! ffmpegAvailable(), 'ffmpeg is not installed');

it('ignores forced or ad-only subtitle tracks and strips promo cues from real ones', function () {
    config(['media.min_subtitle_cues' => 3]);
    makeMkv('Show/e4.mkv', ['per'], ['per' => 'www.king-movie.net']);

    expect(app(MediaProcessor::class)->process('Show/e4.mkv')['subtitles'])->toBe([]);
    Storage::disk('videos')->assertMissing('Show/e4.fa.vtt');
})->skip(fn () => ! ffmpegAvailable(), 'ffmpeg is not installed');

it('adds the video rendition to a master playlist that ffmpeg left without one', function () {
    $audioOnly = '#EXTM3U
#EXT-X-VERSION:7
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="group_aud",NAME="audio_1",DEFAULT=YES,LANGUAGE="fa",URI="paud0.m3u8"
';
    $probe = ['size' => 300_000_000, 'duration' => 3000.0, 'video' => ['codec' => 'h264', 'profile' => 'High', 'level' => 31, 'width' => 854, 'height' => 428]];

    $fixed = app(MediaProcessor::class)->withVideoVariant($audioOnly, $probe);

    expect($fixed)->toContain('#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=854x428,CODECS="avc1.64001f,mp4a.40.2",AUDIO="group_aud"')
        ->toEndWith('
p0.m3u8
');

    // a playlist that already has its video entry is left alone
    expect(app(MediaProcessor::class)->withVideoVariant($fixed, $probe))->toBe($fixed);

    // unknown profile: no CODECS claim rather than a wrong one
    $probe['video']['profile'] = 'Exotic';
    expect(app(MediaProcessor::class)->withVideoVariant($audioOnly, $probe))->not->toContain('CODECS');
});

it('treats warnings that the source already had as harmless but rejects new ones', function () {
    $ffmpeg = Mockery::mock(Ffmpeg::class);
    $ffmpeg->shouldReceive('readWarnings')->with('out.mp4', null)->andReturn(['[h264 @ ] SEI type 5 size 690 truncated at 687']);
    $ffmpeg->shouldReceive('readWarnings')->with('/src.mkv')->andReturn(['[h264 @ ] SEI type 5 size 690 truncated at 687']);
    $processor = new MediaProcessor($ffmpeg);
    $assert = Closure::bind(fn () => $this->assertIntact('out.mp4', null, '/src.mkv'), $processor, MediaProcessor::class);

    expect($assert())->toBeNull();   // identical to the source: fine

    $broken = Mockery::mock(Ffmpeg::class);
    $broken->shouldReceive('readWarnings')->with('out.mp4', null)->andReturn(['[mp4 @ ] Packet corrupt', '[h264 @ ] SEI type 5 size 690 truncated at 687']);
    $broken->shouldReceive('readWarnings')->with('/src.mkv')->andReturn(['[h264 @ ] SEI type 5 size 690 truncated at 687']);
    $assertBroken = Closure::bind(fn () => $this->assertIntact('out.mp4', null, '/src.mkv'), new MediaProcessor($broken), MediaProcessor::class);

    expect($assertBroken)->toThrow(RuntimeException::class, 'Packet corrupt');
});
