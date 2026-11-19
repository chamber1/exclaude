<?php

namespace App\Downloads;

use App\Downloads\Exceptions\DownloadRejected;
use App\Jobs\DownloadJob;
use App\Models\DownloadTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Throwable;

/**
 * Business rules of downloads. Controllers and the queue job stay thin and call this class.
 */
final class DownloadService
{
    public function __construct(
        private readonly UrlParser $parser,
        private readonly NetworkGuard $guard,
        private readonly YtDlp $ytDlp,
    ) {}

    /**
     * Validates the link and returns the video's public metadata (cached per video).
     *
     * @return array{0: MediaReference, 1: MediaInfo}
     *
     * @throws DownloadRejected
     */
    public function inspect(string $url): array
    {
        $media = $this->parser->parse($url);
        $this->guard->assertPublicHost($media->host());

        $info = MediaInfo::fromArray(Cache::remember(
            $media->cacheKey(),
            (int) config('downloads.info_ttl'),
            fn () => $this->ytDlp->inspect($media)->toArray(),
        ));

        $this->assertAllowed($info);

        return [$media, $info];
    }

    /**
     * Creates a task and puts it on the queue.
     *
     * @param  array{url: string, type: string, format?: ?string, bitrate?: ?string, quality?: ?string}  $data
     *
     * @throws DownloadRejected
     */
    public function create(DownloadOwner $owner, array $data): DownloadTask
    {
        [$media, $info] = $this->inspect($data['url']);
        $limits = $owner->limits();
        $isVideo = $data['type'] === 'video';

        $quality = null;
        if ($isVideo) {
            $quality = $data['quality'];
            if ($quality !== 'best' && (int) $quality > $limits['max_height']) {
                throw DownloadRejected::qualityNotAllowed($limits['max_height'], $owner->isGuest());
            }
            // "Best" is capped at the plan's limit.
            if ($quality === 'best' && $limits['max_height'] < 2160) {
                $quality = (string) $limits['max_height'];
            }
        }

        $dailyKey = 'downloads:day:'.$owner->limiterKey();

        // Limits are checked and the task is created under one lock, so parallel requests cannot overshoot them.
        $task = Cache::lock('downloads:create:'.$owner->limiterKey(), 10)->block(5, function () use ($owner, $limits, $dailyKey, $media, $info, $data, $isVideo, $quality) {
            $active = $owner->scopeTasks(DownloadTask::query(), forLimits: true)->active()->count();
            if ($active >= $limits['active']) {
                throw DownloadRejected::tooManyActive($limits['active']);
            }
            if (DownloadTask::query()->active()->count() >= (int) config('downloads.max_queued')) {
                throw DownloadRejected::serviceBusy();
            }
            if (RateLimiter::tooManyAttempts($dailyKey, $limits['per_day'])) {
                throw DownloadRejected::dailyLimit($limits['per_day'], $owner->isGuest());
            }

            $task = DownloadTask::create($owner->attributes() + [
                'platform' => $media->platform->name(),
                'media_id' => $media->id,
                'type' => $isVideo ? 'video' : 'audio',
                'format' => $isVideo ? 'mp4' : $data['format'],
                'bitrate' => $isVideo ? null : $data['bitrate'],
                'quality' => $quality,
                'title' => Str::limit($info->title, 290),
                'duration' => $info->duration,
            ]);
            RateLimiter::hit($dailyKey, 86400);

            return $task;
        });

        DownloadJob::dispatch($task->id);
        $this->log('info', 'task created', $task, ['plan' => $owner->plan()]);

        return $task;
    }

    /**
     * Runs a task (called by DownloadJob). Safe to call again for the same task: a finished task
     * is skipped, a task left "processing" by a crashed worker is started over from scratch.
     */
    public function process(DownloadTask $task): void
    {
        $claimed = DownloadTask::query()->whereKey($task->id)->active()->update([
            'status' => DownloadStatus::Processing,
            'started_at' => now(),
            'attempts' => DB::raw('attempts + 1'),
            'progress' => 0,
            'error_code' => null,
            'error_message' => null,
        ]);
        if ($claimed === 0) {
            $this->log('info', 'task skipped, already '.$task->fresh()?->status->value, $task);

            return;
        }
        $task->refresh();
        $this->log('info', 'task processing', $task, ['attempt' => $task->attempts]);

        $directory = $task->absoluteDirectory();
        // A previous attempt may have left partial files.
        File::deleteDirectory($directory);
        File::ensureDirectoryExists($directory, 0750);

        try {
            $media = $this->parser->reference($task->platform, $task->media_id);
            $this->guard->assertPublicHost($media->host());

            [$path, $title] = $this->ytDlp->download(
                $task,
                $media,
                $directory,
                DownloadOwner::limitsForTask($task)['max_filesize'],
                $this->progressReporter($task),
            );
        } catch (DownloadRejected $e) {
            $this->fail($task, $e->reason, $e->getMessage());

            return;
        } catch (Throwable $e) {
            // Unexpected: remove partial files and let the queue retry (or call DownloadJob::failed()).
            File::deleteDirectory($directory);

            throw $e;
        }

        $suffix = match (true) {
            $task->type === 'video' && $task->quality !== 'best' => " ({$task->quality}p)",
            $task->type === 'audio' && $task->bitrate !== 'best' => " ({$task->bitrate} kbps)",
            default => '',
        };

        $task->update([
            'status' => DownloadStatus::Completed,
            'progress' => 100,
            'title' => Str::limit($title, 290),
            'file_path' => basename($path),
            'file_name' => self::fileName($title, $suffix, pathinfo($path, PATHINFO_EXTENSION)),
            'file_size' => filesize($path),
            'completed_at' => now(),
            'expires_at' => now()->addSeconds((int) config('downloads.file_ttl')),
        ]);
        $this->log('info', 'task completed', $task, [
            'size' => $task->file_size,
            'seconds' => $task->started_at ? (int) $task->started_at->diffInSeconds($task->completed_at, true) : null,
        ]);
    }

    public function fail(DownloadTask $task, string $code, string $message): void
    {
        File::deleteDirectory($task->absoluteDirectory());
        $task->update([
            'status' => DownloadStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'file_path' => null,
            'completed_at' => now(),
        ]);
        $this->log('warning', 'task failed', $task, ['code' => $code]);
    }

    /** Called when the job has failed for good (exception after the last try, timeout, worker killed repeatedly). */
    public function failUnexpectedly(int $taskId, ?Throwable $e): void
    {
        $task = DownloadTask::find($taskId);
        if ($task === null) {
            return;
        }

        Log::channel('downloads')->error('job failed', [
            'task' => $task->uuid,
            'exception' => $e ? $e::class.': '.Str::limit($e->getMessage(), 2000) : null,
        ]);

        if ($task->status->isActive()) {
            $this->fail($task, 'server_error', 'Не удалось обработать загрузку. Попробуйте ещё раз чуть позже.');
        }
    }

    public function expire(DownloadTask $task, string $why): void
    {
        File::deleteDirectory($task->absoluteDirectory());
        $task->update(['status' => DownloadStatus::Expired, 'file_path' => null]);
        $this->log('info', 'task expired: '.$why, $task);
    }

    /** Temporary signed link to the file, valid until the file expires. */
    public function downloadUrl(DownloadTask $task): ?string
    {
        return $task->status === DownloadStatus::Completed && $task->expires_at?->isFuture()
            ? URL::temporarySignedRoute('downloads.file', $task->expires_at, ['task' => $task->uuid], absolute: false)
            : null;
    }

    /**
     * Sends the file. With downloads.delete_after_download the link is single-use: the task becomes
     * "expired" right away and the file is deleted (PHP delivery) or removed by the cleanup after
     * DownloadCleaner::DELIVERY_GRACE (nginx delivery, nginx may still be sending it).
     *
     * With downloads.x_accel_prefix set, PHP only checks access and nginx streams the file
     * (X-Accel-Redirect): no PHP-FPM worker is held for minutes, and Range requests work.
     */
    public function deliver(DownloadTask $task): Response
    {
        if (! $task->isDownloadable()) {
            throw new GoneHttpException('Файл больше недоступен.');
        }

        $path = (string) $task->absoluteFilePath();
        $file = (string) $task->file_path;
        $name = (string) $task->file_name;
        $deleteAfter = (bool) config('downloads.delete_after_download');
        $prefix = (string) config('downloads.x_accel_prefix');

        if ($deleteAfter) {
            // Atomic: of two parallel requests only one gets the file.
            $claimed = DownloadTask::query()->whereKey($task->id)->where('status', DownloadStatus::Completed)
                ->update(['status' => DownloadStatus::Expired, 'downloaded_at' => now()]);
            if ($claimed === 0) {
                throw new GoneHttpException('Файл больше недоступен.');
            }
        } else {
            $task->update(['downloaded_at' => now()]);
        }
        $this->log('info', 'file sent', $task, ['size' => $task->file_size, 'via' => $prefix !== '' ? 'nginx' : 'php']);

        if ($prefix !== '') {
            return response('', 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name, self::asciiFileName($name)),
                'Cache-Control' => 'private, no-store',
                'X-Accel-Redirect' => rtrim($prefix, '/').'/'.$task->uuid.'/'.rawurlencode($file),
            ]);
        }

        $response = response()->download($path, $name, ['Cache-Control' => 'private, no-store']);
        if ($deleteAfter) {
            $directory = $task->absoluteDirectory();
            app()->terminating(fn () => File::deleteDirectory($directory));
            $response->deleteFileAfterSend();
        }

        return $response;
    }

    private static function asciiFileName(string $name): string
    {
        return preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '_', Str::ascii($name)) ?: 'download';
    }

    public static function fileName(string $title, string $suffix, string $ext): string
    {
        $safe = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', '_', $title) ?? 'download';

        return Str::limit(trim($safe, ' ._') ?: 'download', 150, '').$suffix.'.'.$ext;
    }

    /** @throws DownloadRejected */
    private function assertAllowed(MediaInfo $info): void
    {
        if ($info->isLive) {
            throw new DownloadRejected('live', 'Трансляции не скачиваются. Дождитесь, когда запись появится на канале.');
        }

        $max = (int) config('downloads.max_duration');
        if ($info->duration !== null && $info->duration > $max) {
            throw new DownloadRejected('too_long', 'Ролик длиннее '.intdiv($max, 60).' минут, такие не обрабатываются.');
        }

        if (config('downloads.license_policy') === 'creative_commons' && ! $info->isCreativeCommons()) {
            throw new DownloadRejected('license', 'Автор не разрешил свободное использование этого ролика (нет лицензии Creative Commons), поэтому скачать его нельзя.');
        }
    }

    /** Writes progress to the task at most every 2 seconds (the page polls the status). */
    private function progressReporter(DownloadTask $task): callable
    {
        $last = 0.0;

        return function (int $percent) use ($task, &$last): void {
            if (microtime(true) - $last < 2) {
                return;
            }
            $last = microtime(true);
            DownloadTask::query()->whereKey($task->id)->where('status', DownloadStatus::Processing)
                ->where('progress', '<', $percent)->update(['progress' => $percent]);
        };
    }

    private function log(string $level, string $message, DownloadTask $task, array $context = []): void
    {
        Log::channel('downloads')->log($level, $message, [
            'task' => $task->uuid,
            'user_id' => $task->user_id,
            // A prefix of the HMAC is enough to correlate requests of one client in the log.
            'client' => substr($task->client_hash, 0, 12),
            'media' => $task->platform.':'.$task->media_id,
            'type' => $task->type.'/'.$task->format,
        ] + $context);
    }
}
