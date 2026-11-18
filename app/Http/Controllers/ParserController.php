<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ParserController extends Controller
{
    /** Longest video (seconds) we agree to process. */
    private const MAX_DURATION = 3600;

    /** yt-dlp run limit (seconds). */
    private const TIMEOUT = 600;

    /** Largest video file yt-dlp may download (passed to --max-filesize). */
    private const MAX_VIDEO_SIZE = '2G';

    /** How long a prepared video file waits to be downloaded (seconds). */
    private const FILE_TTL = 3600;

    private const FORMATS = ['mp3', 'm4a'];

    /**
     * Bitrate options (kbit/s). "best" = mp3 VBR V0 / m4a original track without re-encoding.
     */
    private const BITRATES = ['best', '320', '256', '192', '128', '96', '64'];

    /**
     * Max video height. Up to 1080p YouTube offers H.264, above that only VP9/AV1.
     */
    private const QUALITIES = ['best', '2160', '1440', '1080', '720', '480', '360'];

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        return view('parser', [
            'formats' => self::FORMATS,
            'bitrates' => self::BITRATES,
            'qualities' => self::QUALITIES,
        ]);
    }

    /**
     * Audio: sends the file directly (small, fetched as a blob).
     * Video: prepares the file and returns JSON with a one-time download link (see file()),
     * so large files are streamed by the browser instead of being held in memory.
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:audio,video'],
            'url' => ['required', 'string', 'max:2048'],
            'format' => ['required_unless:type,video', 'nullable', 'in:'.implode(',', self::FORMATS)],
            'bitrate' => ['required_unless:type,video', 'nullable', 'in:'.implode(',', self::BITRATES)],
            'quality' => ['required_if:type,video', 'nullable', 'in:'.implode(',', self::QUALITIES)],
        ], [
            'bitrate.in' => 'Недопустимый битрейт.',
            'quality.in' => 'Недопустимое качество видео.',
        ]);
        $isVideo = ($data['type'] ?? 'audio') === 'video';

        $videoId = $this->extractVideoId($data['url']);
        if ($videoId === null) {
            throw ValidationException::withMessages([
                'url' => 'Это не похоже на ссылку на ролик YouTube.',
            ]);
        }

        if ($isVideo) {
            $quality = $data['quality'];
            [$file, $title] = $this->runYtDlp($videoId, 'mp4', [
                '-f', 'bv*+ba/b',
                // Resolution first, then prefer H.264 + AAC so the mp4 opens in any player.
                '-S', ($quality === 'best' ? 'res' : 'res:'.$quality).',vcodec:h264,acodec:aac',
                '--merge-output-format', 'mp4',
                '--max-filesize', self::MAX_VIDEO_SIZE,
            ], 'видео');

            $name = $this->fileName($title, $quality === 'best' ? '' : " ({$quality}p)", 'mp4');
            $token = (string) Str::uuid();
            Cache::put('parser:file:'.$token, [
                'user_id' => $request->user()->id,
                'path' => $file,
                'name' => $name,
            ], self::FILE_TTL);

            return response()->json([
                'url' => route('parser.file', $token),
                'name' => $name,
                'size' => filesize($file),
            ]);
        }

        $format = $data['format'];
        $bitrate = $data['bitrate'];
        [$file, $title] = $this->runYtDlp($videoId, $format, [
            '-f', $this->audioFormatSelector($format, $bitrate),
            '--extract-audio',
            '--audio-format', $format,
            // "0" = best VBR; "192K" etc. makes ffmpeg encode with -b:a 192k.
            '--audio-quality', $bitrate === 'best' ? '0' : $bitrate.'K',
        ], 'звук');

        $name = $this->fileName($title, $bitrate === 'best' ? '' : " ({$bitrate} kbps)", $format);

        return response()->download($file, $name)->deleteFileAfterSend();
    }

    /**
     * One-time download of a prepared video file (owner only).
     */
    public function file(Request $request, string $token): BinaryFileResponse
    {
        $entry = Str::isUuid($token) ? Cache::get('parser:file:'.$token) : null;

        abort_unless($entry && $entry['user_id'] === $request->user()->id && is_file($entry['path']), 404);
        Cache::forget('parser:file:'.$token);

        return response()->download($entry['path'], $entry['name'])->deleteFileAfterSend();
    }

    /**
     * Runs yt-dlp for the video and returns [path to the resulting .$ext file, video title].
     *
     * @param  array<int, string>  $args  format-specific options
     */
    private function runYtDlp(string $videoId, string $ext, array $args, string $what): array
    {
        $dir = storage_path('app/parser');
        File::ensureDirectoryExists($dir);
        $this->removeStaleFiles($dir);

        $base = $dir.'/'.Str::uuid();
        set_time_limit(self::TIMEOUT + 60);

        // Only the normalized URL is passed to yt-dlp, never the raw user input.
        $command = [
            config('services.yt_dlp.binary'),
            '--no-playlist',
            '--no-cache-dir',
            '--no-progress',
            // Keep the file's mtime "now", otherwise removeStaleFiles() could delete it mid-request.
            '--no-mtime',
            '--match-filter', 'duration <= '.self::MAX_DURATION,
            ...$args,
            '-o', $base.'.%(ext)s',
            '--no-simulate',
            '-O', 'after_move:%(title)s',
            '--',
            'https://www.youtube.com/watch?v='.$videoId,
        ];

        try {
            $result = Process::timeout(self::TIMEOUT)
                ->env([
                    // php-fpm clears the environment; yt-dlp needs PATH to find ffmpeg and deno.
                    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                    'HOME' => sys_get_temp_dir(),
                    'DENO_DIR' => sys_get_temp_dir().'/deno',
                ])
                ->run($command);
        } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException) {
            $this->removeFiles($base);
            throw ValidationException::withMessages(['url' => 'Обработка заняла слишком много времени. Попробуйте ролик покороче или качество пониже.']);
        }

        $output = $result->output().$result->errorOutput();
        $file = is_file($base.'.'.$ext) ? $base.'.'.$ext : null;

        if (! $result->successful() || $file === null) {
            $this->removeFiles($base);
            Log::warning('yt-dlp failed', ['video' => $videoId, 'ext' => $ext, 'exit' => $result->exitCode(), 'output' => Str::limit($output, 4000)]);

            throw ValidationException::withMessages(['url' => $this->errorMessage($output, $result->exitCode(), $what)]);
        }

        $title = trim(strtok($result->output(), "\n") ?: '') ?: $videoId;

        return [$file, $title];
    }

    private function fileName(string $title, string $suffix, string $ext): string
    {
        return Str::limit(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $title), 150, '').$suffix.'.'.$ext;
    }

    /**
     * yt-dlp's ExtractAudio just copies an AAC source into m4a and ignores --audio-quality.
     * So for m4a with an explicit bitrate, prefer a non-AAC (Opus) source to force a re-encode.
     */
    private function audioFormatSelector(string $format, string $bitrate): string
    {
        if ($format !== 'm4a') {
            return 'bestaudio/best';
        }

        return $bitrate === 'best'
            ? 'bestaudio[ext=m4a]/bestaudio/best'
            : 'bestaudio[acodec^=opus]/bestaudio[acodec!^=mp4a]/bestaudio/best';
    }

    /**
     * Accepts youtube.com/watch?v=, youtu.be/, /shorts/, /embed/, /live/ links
     * (www., m., music. subdomains) and returns the 11-character video id.
     */
    private function extractVideoId(string $url): ?string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($path, '/'))[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            if ($path === '/watch') {
                $id = $query['v'] ?? null;
            } elseif (preg_match('#^/(shorts|embed|live)/([^/?]+)#', $path, $m)) {
                $id = $m[2];
            }
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }

    private function errorMessage(string $output, ?int $exitCode, string $what): string
    {
        $known = [
            'does not pass filter' => 'Ролик длиннее '.(self::MAX_DURATION / 60).' минут, такие не обрабатываются.',
            'larger than max-filesize' => 'Видео в этом качестве больше '.self::MAX_VIDEO_SIZE.'Б. Выберите качество пониже.',
            'Private video' => 'Это приватное видео.',
            'confirm your age' => 'У видео возрастное ограничение, без входа в аккаунт YouTube его не скачать.',
            'not a bot' => 'YouTube временно блокирует запросы с этого сервера (проверка на бота). Попробуйте позже.',
            'Video unavailable' => 'Видео недоступно или удалено.',
            'live event will begin' => 'Трансляция ещё не началась.',
            'members-only' => 'Видео доступно только спонсорам канала.',
            'ffmpeg not found' => 'На сервере не установлен ffmpeg.',
            'No space left' => 'На сервере закончилось место на диске.',
        ];

        foreach ($known as $needle => $message) {
            if (stripos($output, $needle) !== false) {
                return $message;
            }
        }

        if ($exitCode === 127 || $exitCode === 126) {
            return 'На сервере не установлен yt-dlp.';
        }

        return "Не удалось скачать {$what} из этого ролика. Подробности записаны в лог.";
    }

    private function removeFiles(string $base): void
    {
        foreach (glob($base.'.*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Leftovers from interrupted downloads and video files nobody picked up. */
    private function removeStaleFiles(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - self::FILE_TTL) {
                @unlink($f);
            }
        }
    }
}
