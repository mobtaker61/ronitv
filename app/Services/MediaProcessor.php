<?php

namespace App\Services;

use App\Support\SafePath;
use App\Support\SubtitleConverter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Makes one library video browser-friendly:
 *  - text subtitles embedded in the file become sidecar "<name>.<lang>.vtt" files;
 *  - a single-audio file is remuxed to a faststart MP4 (video is never re-encoded);
 *  - a multi-audio file becomes HLS with one audio rendition per track, so the player can switch language.
 */
class MediaProcessor
{
    /** Audio languages are shown with these labels in the player's audio menu. */
    private const AUDIO_LABELS = [
        'fa' => 'فارسی', 'en' => 'English', 'ar' => 'العربية', 'tr' => 'Türkçe',
        'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español', 'ru' => 'Русский', 'ja' => '日本語',
    ];

    private const MAX_AUDIO_TRACKS = 4;

    public function __construct(private Ffmpeg $ffmpeg) {}

    /**
     * @return array{mode: string, subtitles: list<string>, deleted: bool, bytes_before: int, bytes_after: int}
     */
    public function process(string $relativePath, bool $deleteOriginal = false): array
    {
        $relativePath = SafePath::clean($relativePath);
        $videos = Storage::disk('videos');

        if ($relativePath === '' || ! $videos->exists($relativePath)) {
            throw new RuntimeException("Video not found: {$relativePath}");
        }

        $source = $videos->path($relativePath);
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $base = pathinfo($relativePath, PATHINFO_FILENAME);
        $dir = dirname($relativePath) === '.' ? '' : dirname($relativePath);
        $probe = $this->ffmpeg->probe($source);

        if ($probe['video'] === null || $probe['audio'] === []) {
            throw new RuntimeException('File has no video or no audio stream.');
        }

        $this->assertFreeSpace($source, $probe['size']);

        $subtitles = $this->extractSubtitles($probe, $source, $dir, $base);
        $multiAudio = count($probe['audio']) > 1;
        $mode = 'none';
        $after = $probe['size'];

        if ($multiAudio) {
            $after = $this->buildHls($probe, $source, $dir, $base);
            $mode = 'hls';
        } elseif ($extension !== 'mp4') {
            $after = $this->remux($probe, $source, $dir, $base);
            $mode = 'mp4';
        }

        $deleted = false;
        if ($deleteOriginal && $mode !== 'none') {
            $videos->delete($relativePath);
            $deleted = true;
        }

        return [
            'mode' => $mode,
            'subtitles' => $subtitles,
            'deleted' => $deleted,
            'bytes_before' => $probe['size'],
            'bytes_after' => $after,
        ];
    }

    /**
     * Directory (inside the hls disk) that holds the segments for a given playlist path.
     */
    public static function hlsKey(string $dir, string $base): string
    {
        return substr(sha1(($dir !== '' ? $dir.'/' : '').$base), 0, 16);
    }

    /**
     * The segment folder a published playlist points at, read from the playlist itself so the video
     * can be renamed or moved without its HLS folder needing to follow.
     */
    public static function hlsKeyFromPlaylist(string $playlist): ?string
    {
        return preg_match('#/storage/hls/([0-9a-f]{16})/#', $playlist, $m) ? $m[1] : null;
    }

    private function assertFreeSpace(string $source, int $size): void
    {
        $free = @disk_free_space(dirname($source));

        if ($free !== false && $free < $size * 1.1 + config('media.min_free_bytes')) {
            throw new RuntimeException('Not enough free disk space to convert this file safely.');
        }
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return list<string> languages that were written
     */
    private function extractSubtitles(array $probe, string $source, string $dir, string $base): array
    {
        $videos = Storage::disk('videos');
        $best = [];

        foreach ($probe['subtitles'] as $sub) {
            if (! $sub['text']) {
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'sub');
            $vttFile = $tmp.'.vtt';

            try {
                $this->ffmpeg->run(['-i', $source, '-map', '0:'.$sub['index'], '-f', 'webvtt', $vttFile]);
                $content = (string) @file_get_contents($vttFile);
            } catch (RuntimeException) {
                continue;
            } finally {
                @unlink($tmp);
                @unlink($vttFile);
            }

            if (! str_contains($content, '-->')) {
                continue;
            }

            $lang = $sub['lang'] ?? $this->guessLanguage($content);
            if ($lang === null) {
                continue;
            }

            [$content, $cues] = SubtitleConverter::stripPromoCues($content);

            // Several tracks in one language (full / forced / SDH): keep the one with the most dialogue.
            if (! isset($best[$lang]) || $cues > $best[$lang][1]) {
                $best[$lang] = [$content, $cues];
            }
        }

        $written = [];
        foreach ($best as $lang => [$content, $cues]) {
            // Forced / ad-only tracks (a handful of cues) are not subtitles; the online lookup should fill the gap instead.
            if ($cues < config('media.min_subtitle_cues')) {
                continue;
            }

            $target = ($dir !== '' ? $dir.'/' : '').$base.'.'.$lang;

            if ($videos->exists($target.'.vtt') || $videos->exists($target.'.srt')) {
                continue;
            }

            $videos->put($target.'.vtt', $content);
            $written[] = $lang;
        }

        return $written;
    }

    /**
     * Subtitle tracks without a language tag: treat as Persian only when most letters are Arabic-script.
     */
    private function guessLanguage(string $vtt): ?string
    {
        $letters = preg_match_all('/\p{L}/u', $vtt);
        $arabic = preg_match_all('/\p{Arabic}/u', $vtt);

        return $letters > 50 && $arabic / $letters > 0.5 ? 'fa' : null;
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function remux(array $probe, string $source, string $dir, string $base): int
    {
        $videos = Storage::disk('videos');
        $target = ($dir !== '' ? $dir.'/' : '').$base.'.mp4';

        if ($videos->exists($target)) {
            throw new RuntimeException("{$target} already exists.");
        }

        $audio = $probe['audio'][0];
        $final = $videos->path($target);
        $partial = $final.'.part';

        try {
            $this->ffmpeg->run([
                '-i', $source,
                '-map', '0:'.$probe['video']['index'],
                '-map', '0:'.$audio['index'],
                '-c:v', 'copy',
                ...$this->audioArgs($audio, 0),
                '-sn', '-movflags', '+faststart', '-f', 'mp4', $partial,
            ]);

            $output = $this->ffmpeg->probe($partial);
            $this->assertSameDuration($probe['duration'], $output['duration']);
            $this->assertStreams($output, 1);
            $this->ffmpeg->assertReadable($partial);
            rename($partial, $final);
        } finally {
            @unlink($partial);
        }

        return (int) filesize($final);
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return int bytes written
     */
    private function buildHls(array $probe, string $source, string $dir, string $base): int
    {
        $videos = Storage::disk('videos');
        $hls = Storage::disk('hls');
        $playlist = ($dir !== '' ? $dir.'/' : '').$base.'.m3u8';

        if ($videos->exists($playlist)) {
            throw new RuntimeException("{$playlist} already exists.");
        }

        $key = self::hlsKey($dir, $base);
        $hls->deleteDirectory($key);
        $hls->makeDirectory($key);
        $work = $hls->path($key);

        $audioTracks = array_slice($probe['audio'], 0, self::MAX_AUDIO_TRACKS);
        $defaultIndex = 0;
        foreach ($audioTracks as $i => $track) {
            if ($track['lang'] === 'fa') {
                $defaultIndex = $i;
                break;
            }
        }

        $args = ['-i', $source, '-map', '0:'.$probe['video']['index'], '-c:v', 'copy'];
        $streamMap = ['v:0,agroup:aud'];
        $labels = [];

        foreach ($audioTracks as $i => $track) {
            $args[] = '-map';
            $args[] = '0:'.$track['index'];
            array_push($args, ...$this->audioArgs($track, $i));

            $lang = $track['lang'] ?? 'und';
            $labels["aud{$i}"] = (self::AUDIO_LABELS[$lang] ?? strtoupper($lang)).$this->duplicateSuffix($audioTracks, $i);
            $streamMap[] = "a:{$i},agroup:aud,language:{$lang},name:aud{$i}".($i === $defaultIndex ? ',default:yes' : '');
        }

        array_push(
            $args,
            '-f', 'hls', '-hls_time', '6', '-hls_playlist_type', 'vod', '-hls_segment_type', 'fmp4',
            '-hls_flags', 'independent_segments', '-master_pl_name', 'master.m3u8',
            '-hls_fmp4_init_filename', 'init_%v.mp4', '-hls_segment_filename', 'seg_%v_%05d.m4s',
            '-var_stream_map', implode(' ', $streamMap), 'p%v.m3u8',
        );

        try {
            $this->ffmpeg->run($args, $work);

            // ffmpeg omits the video entry of the master playlist when the source carries no bitrate tags.
            file_put_contents($work.DIRECTORY_SEPARATOR.'master.m3u8', $this->withVideoVariant((string) file_get_contents($work.DIRECTORY_SEPARATOR.'master.m3u8'), $probe));

            // Variant 0 is the video rendition: its duration must match the source.
            $this->assertSameDuration($probe['duration'], $this->ffmpeg->probe($work.DIRECTORY_SEPARATOR.'p0.m3u8')['duration']);

            // The master playlist must expose the video and every audio track, and read through without a single warning.
            $this->assertStreams($this->ffmpeg->probe($work.DIRECTORY_SEPARATOR.'master.m3u8'), count($audioTracks));
            $this->ffmpeg->assertReadable('master.m3u8', $work);

            $videos->put($playlist, $this->publicMaster($hls->get($key.'/master.m3u8'), $key, $labels));
        } catch (\Throwable $e) {
            $hls->deleteDirectory($key);
            throw $e;
        }

        return array_sum(array_map(fn ($f) => $hls->size($f), $hls->files($key)));
    }

    /**
     * Make sure the master playlist lists the video rendition. When ffmpeg could not tell the bitrate it writes only the
     * audio entries, and players then have nothing to play. The values come from the probed source file.
     *
     * @param  array<string, mixed>  $probe
     */
    public function withVideoVariant(string $master, array $probe): string
    {
        if (str_contains($master, '#EXT-X-STREAM-INF')) {
            return $master;
        }

        $video = $probe['video'];
        $attributes = ['BANDWIDTH='.max(1, (int) round($probe['size'] * 8 / max(1.0, $probe['duration'])))];

        if ($video['width'] && $video['height']) {
            $attributes[] = "RESOLUTION={$video['width']}x{$video['height']}";
        }

        if ($codec = $this->avcCodec($video)) {
            $attributes[] = 'CODECS="'.$codec.',mp4a.40.2"';
        }

        $attributes[] = 'AUDIO="group_aud"';

        return rtrim($master).'
#EXT-X-STREAM-INF:'.implode(',', $attributes).'
p0.m3u8
';
    }

    /**
     * RFC 6381 string such as avc1.64001f; null when the profile is not one we can state with certainty.
     *
     * @param  array<string, mixed>  $video
     */
    private function avcCodec(array $video): ?string
    {
        $profile = ['Constrained Baseline' => '42c0', 'Baseline' => '4200', 'Main' => '4d00', 'High' => '6400', 'High 10' => '6e00'][$video['profile'] ?? ''] ?? null;

        return $video['codec'] === 'h264' && $profile && $video['level'] > 0 ? sprintf('avc1.%s%02x', $profile, $video['level']) : null;
    }

    /**
     * The master playlist is published next to the video; its URIs must point at the hls disk,
     * and the NAME attributes get human-readable language labels.
     *
     * @param  array<string, string>  $labels
     */
    private function publicMaster(string $master, string $key, array $labels): string
    {
        $prefix = '/storage/hls/'.$key.'/';
        $lines = preg_split('/\r?\n/', trim($master));

        foreach ($lines as &$line) {
            if ($line === '') {
                continue;
            }

            if ($line[0] === '#') {
                // ffmpeg names audio renditions itself; recover the track from its playlist file (paud<N>.m3u8).
                if (preg_match('/URI="(?:[^"]*\/)?p(aud\d+)\.m3u8"/', $line, $track) && isset($labels[$track[1]])) {
                    $line = preg_replace('/NAME="[^"]*"/', 'NAME="'.$labels[$track[1]].'"', $line);
                }

                $line = preg_replace_callback('/URI="([^"]+)"/', fn ($m) => 'URI="'.$prefix.$m[1].'"', $line);

                continue;
            }

            $line = $prefix.$line;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $tracks
     */
    private function duplicateSuffix(array $tracks, int $i): string
    {
        $same = array_keys(array_filter($tracks, fn ($t) => $t['lang'] === $tracks[$i]['lang']));

        return count($same) > 1 ? ' '.(array_search($i, $same) + 1) : '';
    }

    /**
     * AAC stereo is copied untouched; anything else (MP3, AC3, 5.1…) becomes 128k stereo AAC.
     *
     * @param  array<string, mixed>  $track
     * @return list<string>
     */
    private function audioArgs(array $track, int $outputIndex): array
    {
        if ($track['codec'] === 'aac' && $track['channels'] <= 2) {
            return ["-c:a:{$outputIndex}", 'copy'];
        }

        return ["-c:a:{$outputIndex}", 'aac', "-b:a:{$outputIndex}", '128k', "-ac:a:{$outputIndex}", '2'];
    }

    /**
     * @param  array<string, mixed>  $output  a probe() result
     */
    private function assertStreams(array $output, int $expectedAudio): void
    {
        if ($output['video'] === null || count($output['audio']) !== $expectedAudio) {
            throw new RuntimeException(sprintf('Output has %d audio track(s), expected %d.', count($output['audio']), $expectedAudio));
        }
    }

    private function assertSameDuration(float $expected, float $actual): void
    {
        if ($expected > 0 && abs($expected - $actual) > max(2.0, $expected * 0.005)) {
            throw new RuntimeException(sprintf('Output duration %.1fs differs from source %.1fs.', $actual, $expected));
        }
    }
}
