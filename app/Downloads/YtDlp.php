<?php

namespace App\Downloads;

use App\Downloads\Exceptions\DownloadFailed;
use App\Models\DownloadTask;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The only place that starts yt-dlp.
 *
 * Safety rules kept by every command:
 *  - an argument array (no shell, no string concatenation), the URL after "--";
 *  - the URL is MediaReference::url(), rebuilt from the media id, never user input;
 *  - --ignore-config: no config files from HOME, /etc or the working directory;
 *  - --use-extractors <platform>: the "generic" extractor (arbitrary URLs, redirects) is disabled;
 *  - --no-playlist, a process timeout, --max-filesize and a duration/live/licence match filter.
 */
final class YtDlp
{
    public const FORMAT_MARKER = 'PARSER_FORMAT:';

    public const PROGRESS_MARKER = 'PARSER_PROGRESS:';

    public const FILE_MARKER = 'PARSER_FILE:';

    public const TITLE_MARKER = 'PARSER_TITLE:';

    public function inspect(MediaReference $media): MediaInfo
    {
        $command = [
            ...$this->baseCommand($media),
            '--skip-download',
            '--dump-single-json',
            '--',
            $media->url(),
        ];

        $result = $this->run($command, (int) config('downloads.yt_dlp.inspect_timeout'), null, ['media' => $media->id, 'step' => 'inspect']);
        $json = json_decode($result->output(), true);

        if (! $result->successful() || ! is_array($json)) {
            $this->logFailure('yt-dlp inspect failed', $result, ['media' => $media->id]);

            throw YtDlpErrors::failure($result->errorOutput(), $result->exitCode(), 'информацию о ролике');
        }

        return MediaInfo::fromYtDlp($json);
    }

    /**
     * Downloads the task's media into $directory and returns [absolute file path, title].
     *
     * @param  callable(int): void  $onProgress  receives the overall percentage (0–95)
     *
     * @throws DownloadFailed
     */
    public function download(DownloadTask $task, MediaReference $media, string $directory, string $maxFilesize, callable $onProgress): array
    {
        $command = [
            ...$this->baseCommand($media),
            '--match-filter', $this->matchFilter(),
            '--max-filesize', $maxFilesize,
            ...$this->formatArguments($task),
            '-o', $directory.'/media.%(ext)s',
            '--no-simulate',
            // -O implies --quiet, which would hide "does not pass filter" and the progress lines.
            '--no-quiet',
            '--newline',
            '--progress',
            '--progress-template', 'download:'.self::PROGRESS_MARKER.'%(progress.downloaded_bytes)s:%(progress.total_bytes)s:%(progress.total_bytes_estimate)s',
            '-O', 'before_dl:'.self::FORMAT_MARKER.'%(format_id)s',
            '-O', 'after_move:'.self::FILE_MARKER.'%(filepath)s',
            '-O', 'after_move:'.self::TITLE_MARKER.'%(title)s',
            '--',
            $media->url(),
        ];

        $tracker = new ProgressTracker;
        $context = ['task' => $task->uuid, 'media' => $media->id];
        $what = $task->type === 'video' ? 'видео' : 'звук';

        $result = $this->run($command, (int) config('downloads.yt_dlp.timeout'), function (string $type, string $chunk) use ($tracker, $onProgress) {
            // With --print, yt-dlp writes progress and messages to stderr and only our markers to stdout.
            if (($percent = $tracker->feed($chunk, $type)) !== null) {
                $onProgress($percent);
            }
        }, $context);

        $file = $this->marker($result->output(), self::FILE_MARKER);
        $real = $file !== null ? realpath($file) : false;
        // The file must be inside the task directory (it always is, the template is ours; this is a guard).
        $valid = $real !== false && is_file($real) && str_starts_with($real, realpath($directory).DIRECTORY_SEPARATOR);

        if (! $result->successful() || ! $valid) {
            $this->logFailure('yt-dlp download failed', $result, $context + [
                'reason' => $result->successful() ? 'exit 0 but no file (skipped by a filter?)' : 'non-zero exit',
            ]);

            throw YtDlpErrors::failure($result->output().$result->errorOutput(), $result->exitCode(), $what);
        }

        preg_match_all('/^WARNING:.*$/m', $result->errorOutput(), $warnings);
        Log::channel('downloads')->info('yt-dlp download done', $context + [
            'size' => filesize($real),
            'warnings' => array_slice($warnings[0], 0, 20),
        ]);

        return [$real, $this->marker($result->output(), self::TITLE_MARKER) ?: $media->id];
    }

    /** @return list<string> options shared by every call */
    public function baseCommand(MediaReference $media): array
    {
        return [
            (string) config('downloads.yt_dlp.binary'),
            '--ignore-config',
            '--use-extractors', $media->platform->extractors(),
            '--no-playlist',
            '--no-cache-dir',
            '--no-mtime',
            '--socket-timeout', '30',
        ];
    }

    public function matchFilter(): string
    {
        $filter = 'duration <= '.(int) config('downloads.max_duration').' & !is_live';
        if (config('downloads.license_policy') === 'creative_commons') {
            $filter .= " & license ~= '(?i)creative commons'";
        }

        return $filter;
    }

    /** @return list<string> */
    public function formatArguments(DownloadTask $task): array
    {
        if ($task->type === 'video') {
            $height = $task->quality === 'best' ? null : (int) $task->quality;

            return [
                '-f', 'bv*+ba/b',
                // Resolution first, then prefer H.264 + AAC so the mp4 opens in any player.
                '-S', ($height === null ? 'res' : 'res:'.$height).',vcodec:h264,acodec:aac',
                '--merge-output-format', 'mp4',
            ];
        }

        return [
            '-f', $this->audioFormatSelector($task->format, $task->bitrate),
            '--extract-audio',
            '--audio-format', $task->format,
            // "0" = best VBR; "192K" etc. makes ffmpeg encode with -b:a 192k.
            '--audio-quality', $task->bitrate === 'best' ? '0' : $task->bitrate.'K',
        ];
    }

    /**
     * yt-dlp's ExtractAudio copies an AAC source into m4a as is and ignores --audio-quality,
     * so m4a with an explicit bitrate prefers a non-AAC (Opus) source to force a re-encode.
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

    private function run(array $command, int $timeout, ?callable $output, array $context): ProcessResult
    {
        Log::channel('downloads')->debug('yt-dlp start', $context + [
            'command' => implode(' ', array_map('escapeshellarg', $command)),
        ]);
        $started = microtime(true);

        try {
            $result = Process::timeout($timeout)
                ->env([
                    // php-fpm and queue workers may run with a stripped environment.
                    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                    'HOME' => sys_get_temp_dir(),
                    'DENO_DIR' => sys_get_temp_dir().'/deno',
                ])
                ->run($command, $output);
        } catch (ProcessTimedOutException $e) {
            Log::channel('downloads')->warning('yt-dlp timed out', $context + [
                'timeout' => $timeout,
                'stderr' => Str::limit($e->result->errorOutput(), 8000),
            ]);

            throw YtDlpErrors::timeout();
        }

        Log::channel('downloads')->debug('yt-dlp finished', $context + [
            'exit' => $result->exitCode(),
            'seconds' => round(microtime(true) - $started, 1),
        ]);

        return $result;
    }

    private function logFailure(string $message, ProcessResult $result, array $context): void
    {
        // Progress lines carry no information about the failure.
        $clean = fn (string $s) => Str::limit((string) preg_replace('/^'.preg_quote(self::PROGRESS_MARKER, '/').'.*\n/m', '', $s), 8000);

        Log::channel('downloads')->warning($message, $context + [
            'exit' => $result->exitCode(),
            'stdout' => $clean($result->output()),
            'stderr' => $clean($result->errorOutput()),
        ]);
    }

    private function marker(string $output, string $marker): ?string
    {
        return preg_match('/^'.preg_quote($marker, '/').'(.*)$/m', $output, $m) ? (trim($m[1]) ?: null) : null;
    }
}
