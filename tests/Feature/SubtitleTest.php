<?php

use App\Services\VideoLibrary;
use App\Support\SubtitleConverter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

const SRT = "1\n00:00:01,000 --> 00:00:02,500\nسلام دنیا\n\n2\n00:00:03,000 --> 00:00:04,000\n{\\an8}Hello\n";

beforeEach(function () {
    config([
        'admin.user' => 'admin',
        'admin.password_hash' => Hash::make('secret'),
    ]);
    Storage::fake('videos');
    Cache::flush();
});

function adminAuth(): array
{
    return ['Authorization' => 'Basic '.base64_encode('admin:secret')];
}

it('converts srt to webvtt', function () {
    $vtt = SubtitleConverter::toVtt(SRT, 'srt');

    expect($vtt)->toStartWith("WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.500\nسلام دنیا")
        ->and($vtt)->toContain('00:00:03.000 --> 00:00:04.000')
        ->and($vtt)->not->toContain('{\an8}')
        ->and($vtt)->not->toContain(',000');
});

it('handles BOMs and UTF-16', function () {
    expect(SubtitleConverter::toVtt("\xEF\xBB\xBF".SRT, 'srt'))->toContain('سلام دنیا')
        ->and(SubtitleConverter::toVtt("\xFF\xFE".mb_convert_encoding(SRT, 'UTF-16LE', 'UTF-8'), 'srt'))->toContain('سلام دنیا')
        ->and(SubtitleConverter::toVtt("\xFE\xFF".mb_convert_encoding(SRT, 'UTF-16BE', 'UTF-8'), 'srt'))->toContain('سلام دنیا');
});

it('decodes Windows-1256 Persian and normalises Arabic yeh/kaf', function () {
    $raw = iconv('UTF-8', 'CP1256', "1\n00:00:01,000 --> 00:00:02,000\nكتاب يوم\n");

    expect(mb_check_encoding($raw, 'UTF-8'))->toBeFalse()
        ->and(SubtitleConverter::toVtt($raw, 'srt'))->toContain('کتاب یوم');
});

it('passes vtt through and rejects junk', function () {
    expect(SubtitleConverter::toVtt("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi", 'vtt'))->toStartWith("WEBVTT\n\n00:00")
        ->and(fn () => SubtitleConverter::toVtt('just text', 'srt'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SubtitleConverter::toVtt(SRT, 'ass'))->toThrow(InvalidArgumentException::class);
});

it('discovers sidecar subtitles by name and language', function () {
    $disk = Storage::disk('videos');
    foreach (['Show/e1.mkv', 'Show/e1.srt', 'Show/e1.en.srt', 'Show/e1.en.vtt', 'Show/e1.fa.vtt', 'Show/e2.mkv', 'Show/e10.mkv', 'Show/other.srt', 'Show/e1x.srt'] as $f) {
        $disk->put($f, 'x');
    }

    $videos = app(VideoLibrary::class)->videos($disk, 'Show');

    expect(array_column($videos, 'name'))->toBe(['e1.mkv', 'e2.mkv', 'e10.mkv']);   // natural order
    $subs = $videos[0]['subtitles'];
    expect(array_column($subs, 'lang'))->toBe(['en', 'fa'])                          // un-suffixed .srt counts as fa; .fa.vtt wins
        ->and($subs[1]['path'])->toBe('Show/e1.fa.vtt')
        ->and($subs[0]['path'])->toBe('Show/e1.en.vtt')                              // vtt preferred over srt
        ->and($videos[1]['subtitles'])->toBe([]);
});

it('serves srt as vtt and refuses anything else', function () {
    Storage::disk('videos')->put('Show/e1.srt', SRT);
    Storage::disk('videos')->put('Show/e1.mkv', 'x');

    $this->get('/subtitle/Show/e1.srt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/vtt; charset=UTF-8')
        ->assertSee('00:00:01.000 --> 00:00:02.500', false);

    $this->get('/subtitle/Show/e1.mkv')->assertNotFound();
    $this->get('/subtitle/Show/missing.srt')->assertNotFound();
    $this->get('/subtitle/%2e%2e/%2e%2e/x.srt')->assertNotFound();
});

it('requires admin to add or remove subtitles', function () {
    $this->post('/subtitles')->assertUnauthorized();
    $this->delete('/subtitles')->assertUnauthorized();
});

it('lets the admin add a subtitle, stored as vtt next to the video', function () {
    $disk = Storage::disk('videos');
    $disk->put('Show/e1.mkv', 'x');
    $disk->put('Show/e1.fa.srt', 'old');

    $this->withHeaders(adminAuth())->post('/subtitles', [
        'video' => 'Show/e1.mkv',
        'lang' => 'fa',
        'subtitle' => UploadedFile::fake()->createWithContent('anything.srt', SRT),
    ])->assertSessionHas('success');

    $disk->assertExists('Show/e1.fa.vtt');
    $disk->assertMissing('Show/e1.fa.srt');
    expect($disk->get('Show/e1.fa.vtt'))->toStartWith('WEBVTT');
});

it('rejects bad subtitle uploads', function (array $input, string $expect) {
    Storage::disk('videos')->put('Show/e1.mkv', 'x');

    $response = $this->withHeaders(adminAuth())->post('/subtitles', $input + [
        'video' => 'Show/e1.mkv',
        'lang' => 'en',
        'subtitle' => UploadedFile::fake()->createWithContent('a.srt', SRT),
    ]);

    $expect === 'error' ? $response->assertSessionHas('error') : $response->assertSessionHasErrors($expect);
    Storage::disk('videos')->assertMissing('Show/e1.en.vtt');
})->with([
    'traversal' => [['video' => '../../etc/passwd'], 'error'],
    'missing video' => [['video' => 'Show/none.mkv'], 'error'],
    'not a video' => [['video' => 'Show/e1.fa.vtt'], 'error'],
    'bad language' => [['lang' => 'xx'], 'lang'],
    'wrong extension' => [['subtitle' => UploadedFile::fake()->createWithContent('a.php', '<?php')], 'subtitle'],
    'not subtitles' => [['subtitle' => UploadedFile::fake()->createWithContent('a.srt', 'hello')], 'error'],
]);

it('lets the admin delete only subtitle files', function () {
    $disk = Storage::disk('videos');
    $disk->put('Show/e1.mkv', 'x');
    $disk->put('Show/e1.fa.vtt', 'WEBVTT');

    $this->withHeaders(adminAuth())->delete('/subtitles', ['path' => 'Show/e1.mkv'])->assertSessionHas('error');
    $disk->assertExists('Show/e1.mkv');

    $this->withHeaders(adminAuth())->delete('/subtitles', ['path' => 'Show/e1.fa.vtt'])->assertSessionHas('success');
    $disk->assertMissing('Show/e1.fa.vtt');
});

it('embeds playable data in the folder page, safely, and flags the admin', function () {
    $disk = Storage::disk('videos');
    $disk->put("Show/S1/a'b&c.mkv", 'x');
    $disk->put("Show/S1/a'b&c.en.vtt", 'WEBVTT');
    $disk->put('Show/S2/e1.mp4', 'x');

    $guest = $this->get('/folder/Show');
    $guest->assertOk()->assertSee('id="watchData"', false)->assertDontSee('subtitleModal', false);
    expect($guest->getContent())->not->toContain("a'b&c.mkv");   // quotes and ampersands are \u-escaped inside the JSON block

    $this->withHeaders(adminAuth())->get('/folder/Show')->assertOk()->assertSee('id="subtitleModal"', false);

    preg_match('#<script type="application/json" id="watchData">(.*?)</script>#s', $guest->getContent(), $m);
    $data = json_decode($m[1], true);

    expect(array_column($data['seasons'], 'name'))->toBe(['S1', 'S2'])
        ->and($data['seasons'][0]['videos'][0]['subtitles'][0]['lang'])->toBe('en')
        ->and($data['admin'])->toBeFalse();
});

it('strips promo cues and counts the dialogue that remains', function () {
    $vtt = 'WEBVTT

00:00.000 --> 00:10.000
دانلود کنید
.::WWW.KING-MOVIE.NET::.

00:20.000 --> 00:22.000
سلام

00:30.000 --> 00:32.000
https://example.com/x

00:40.000 --> 00:42.000
خداحافظ
';

    [$clean, $cues] = SubtitleConverter::stripPromoCues($vtt);

    expect($cues)->toBe(2)
        ->and($clean)->toStartWith('WEBVTT

')->toContain('سلام')->toContain('خداحافظ')
        ->not->toContain('KING-MOVIE')->not->toContain('example.com');
});
