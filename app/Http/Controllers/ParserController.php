<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

    private const FORMATS = ['mp3', 'm4a'];

    /**
     * Bitrate options (kbit/s). "best" = mp3 VBR V0 (~245 kbit/s) / m4a original track without re-encoding.
     */
    private const BITRATES = ['best', '320', '256', '192', '128', '96', '64'];

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        return view('parser', ['formats' => self::FORMATS, 'bitrates' => self::BITRATES]);
    }

    /**
     * Download the audio track of a YouTube video and send it to the browser.
     */
    public function download(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'format' => ['required', 'in:'.implode(',', self::FORMATS)],
            'bitrate' => ['required', 'in:'.implode(',', self::BITRATES)],
        ], [
            'bitrate.in' => 'Недопустимый битрейт.',
        ]);
        $bitrate = $data['bitrate'];

        $videoId = $this->extractVideoId($data['url']);
        if ($videoId === null) {
            throw ValidationException::withMessages([
                'url' => 'Это не похоже на ссылку на ролик YouTube.',
            ]);
        }

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
            '--match-filter', 'duration <= '.self::MAX_DURATION,
            '-f', $this->formatSelector($data['format'], $bitrate),
            '--extract-audio',
            '--audio-format', $data['format'],
            // "0" = best VBR; "192K" etc. makes ffmpeg encode with -b:a 192k.
            '--audio-quality', $bitrate === 'best' ? '0' : $bitrate.'K',
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
            throw ValidationException::withMessages(['url' => 'Обработка заняла слишком много времени. Попробуйте ролик покороче.']);
        }

        $output = $result->output().$result->errorOutput();
        $files = glob($base.'.*') ?: [];
        $file = collect($files)->first(fn ($f) => str_ends_with($f, '.'.$data['format']));

        if (! $result->successful() || $file === null) {
            $this->removeFiles($base);
            Log::warning('yt-dlp failed', ['video' => $videoId, 'exit' => $result->exitCode(), 'output' => Str::limit($output, 4000)]);

            throw ValidationException::withMessages(['url' => $this->errorMessage($output, $result->exitCode())]);
        }

        $title = trim(strtok($result->output(), "\n") ?: '') ?: $videoId;
        $name = Str::limit(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $title), 150, '')
            .($bitrate === 'best' ? '' : " ({$bitrate} kbps)")
            .'.'.$data['format'];

        return response()->download($file, $name)->deleteFileAfterSend();
    }

    /**
     * yt-dlp's ExtractAudio just copies an AAC source into m4a and ignores --audio-quality.
     * So for m4a with an explicit bitrate, prefer a non-AAC (Opus) source to force a re-encode.
     */
    private function formatSelector(string $format, string $bitrate): string
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

    private function errorMessage(string $output, ?int $exitCode): string
    {
        $known = [
            'does not pass filter' => 'Ролик длиннее '.(self::MAX_DURATION / 60).' минут, такие не обрабатываются.',
            'Private video' => 'Это приватное видео.',
            'confirm your age' => 'У видео возрастное ограничение, без входа в аккаунт YouTube его не скачать.',
            'not a bot' => 'YouTube временно блокирует запросы с этого сервера (проверка на бота). Попробуйте позже.',
            'Video unavailable' => 'Видео недоступно или удалено.',
            'live event will begin' => 'Трансляция ещё не началась.',
            'members-only' => 'Видео доступно только спонсорам канала.',
            'ffmpeg not found' => 'На сервере не установлен ffmpeg.',
        ];

        foreach ($known as $needle => $message) {
            if (stripos($output, $needle) !== false) {
                return $message;
            }
        }

        if ($exitCode === 127 || $exitCode === 126) {
            return 'На сервере не установлен yt-dlp.';
        }

        return 'Не удалось скачать звук из этого ролика. Подробности записаны в лог.';
    }

    private function removeFiles(string $base): void
    {
        foreach (glob($base.'.*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Leftovers from interrupted downloads. */
    private function removeStaleFiles(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
    }
}
