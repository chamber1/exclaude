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

    /** Prefix of the stdout line with the video title (stdout also carries yt-dlp's own messages). */
    private const TITLE_MARKER = 'PARSER_TITLE:';

    /** Shared context for every parser.log entry of the current request. */
    private array $logContext = [];

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
        // "rid" ties together all log lines of one request.
        $this->logContext = [
            'rid' => Str::lower(Str::random(8)),
            'user_id' => $request->user()->id,
            'ip' => $request->ip(),
        ];
        $params = $request->only(['type', 'format', 'bitrate', 'quality']);
        $this->log('info', 'request', $params + ['url' => Str::limit((string) $request->input('url'), 300)]);

        try {
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
        } catch (ValidationException $e) {
            $this->log('notice', 'validation failed', ['errors' => $e->errors()]);
            throw $e;
        }
        $isVideo = ($data['type'] ?? 'audio') === 'video';

        $videoId = $this->extractVideoId($data['url']);
        if ($videoId === null) {
            $this->log('notice', 'not a YouTube video URL');
            throw ValidationException::withMessages([
                'url' => 'Это не похоже на ссылку на ролик YouTube.',
            ]);
        }
        $this->logContext['video'] = $videoId;

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
            $this->log('info', 'video prepared, waiting for download', ['token' => $token, 'name' => $name, 'size' => filesize($file)]);

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
        $this->log('info', 'audio sent', ['name' => $name, 'size' => filesize($file)]);

        return response()->download($file, $name)->deleteFileAfterSend();
    }

    /**
     * One-time download of a prepared video file (owner only).
     */
    public function file(Request $request, string $token): BinaryFileResponse
    {
        $this->logContext = ['user_id' => $request->user()->id, 'ip' => $request->ip(), 'token' => $token];
        $entry = Str::isUuid($token) ? Cache::get('parser:file:'.$token) : null;

        $denied = match (true) {
            ! Str::isUuid($token) => 'malformed token',
            $entry === null => 'unknown, used or expired token',
            $entry['user_id'] !== $request->user()->id => 'token belongs to user '.$entry['user_id'],
            ! is_file($entry['path']) => 'file missing on disk',
            default => null,
        };
        if ($denied !== null) {
            // Someone else's token is worth a closer look; the rest is routine.
            $this->log(str_starts_with($denied, 'token belongs') ? 'warning' : 'notice', 'file download denied: '.$denied);
            abort(404);
        }

        Cache::forget('parser:file:'.$token);
        $this->log('info', 'video sent', ['name' => $entry['name'], 'size' => filesize($entry['path'])]);

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
            // -O implies --quiet, which would hide messages like "does not pass filter" that errorMessage() needs.
            '--no-quiet',
            '-O', 'after_move:'.self::TITLE_MARKER.'%(title)s',
            '--',
            'https://www.youtube.com/watch?v='.$videoId,
        ];

        $this->log('debug', 'yt-dlp start', ['command' => implode(' ', array_map('escapeshellarg', $command))]);
        $started = microtime(true);

        try {
            $result = Process::timeout(self::TIMEOUT)
                ->env([
                    // php-fpm clears the environment; yt-dlp needs PATH to find ffmpeg and deno.
                    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                    'HOME' => sys_get_temp_dir(),
                    'DENO_DIR' => sys_get_temp_dir().'/deno',
                ])
                ->run($command);
        } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException $e) {
            $this->removeFiles($base);
            $message = 'Обработка заняла слишком много времени. Попробуйте ролик покороче или качество пониже.';
            $this->log('warning', 'yt-dlp timed out', [
                'seconds' => round(microtime(true) - $started, 1),
                'timeout' => self::TIMEOUT,
                'stderr' => Str::limit($e->result->errorOutput(), 8000),
                'user_message' => $message,
            ]);

            throw ValidationException::withMessages(['url' => $message]);
        }

        $seconds = round(microtime(true) - $started, 1);
        $output = $result->output().$result->errorOutput();
        $file = is_file($base.'.'.$ext) ? $base.'.'.$ext : null;

        if (! $result->successful() || $file === null) {
            $this->removeFiles($base);
            $message = $this->errorMessage($output, $result->exitCode(), $what);
            $this->log('warning', 'yt-dlp failed', [
                'exit' => $result->exitCode(),
                'seconds' => $seconds,
                'reason' => $result->successful() ? 'exit 0 but no .'.$ext.' file (skipped by a filter?)' : 'non-zero exit',
                'user_message' => $message,
                'stdout' => Str::limit($result->output(), 8000),
                'stderr' => Str::limit($result->errorOutput(), 8000),
            ]);

            throw ValidationException::withMessages(['url' => $message]);
        }

        $title = preg_match('/^'.preg_quote(self::TITLE_MARKER, '/').'(.*)$/m', $result->output(), $m)
            ? (trim($m[1]) ?: $videoId)
            : $videoId;
        preg_match_all('/^WARNING:.*$/m', $result->errorOutput(), $warnings);
        $this->log('info', 'yt-dlp done', [
            'seconds' => $seconds,
            'title' => $title,
            'size' => filesize($file),
            'warnings' => array_slice($warnings[0], 0, 20),
        ]);

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
            'video is unavailable' => 'Видео недоступно или удалено.',
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

        return "Не удалось скачать {$what} из этого ролика.";
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('parser')->log($level, $message, $this->logContext + $context);
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
