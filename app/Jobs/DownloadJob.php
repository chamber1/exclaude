<?php

namespace App\Jobs;

use App\Downloads\DownloadService;
use App\Models\DownloadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Processes one DownloadTask on the "downloads" queue.
 *
 * Failure handling:
 *  - expected failures (private video, too large...) fail the task inside DownloadService, the job succeeds;
 *  - an unexpected exception is retried once ($tries), then failed() marks the task failed;
 *  - if the worker dies mid-run (OOM, container restart), the queue hands the job out again after
 *    retry_after and DownloadService::process() starts the task over; after $tries attempts failed() runs;
 *  - a task whose job was lost entirely is failed by `downloads:cleanup` (stuck tasks).
 */
class DownloadJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    /** Kill the job (and mark it failed) if it runs longer than this; must stay below retry_after. */
    public int $timeout;

    public bool $failOnTimeout = true;

    /** The unique lock is released when the job finishes; this only bounds a lock left by a lost job. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $taskId)
    {
        $this->timeout = (int) config('downloads.yt_dlp.timeout') + 60;
        $this->onQueue(config('downloads.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->taskId;
    }

    public function handle(DownloadService $downloads): void
    {
        $task = DownloadTask::find($this->taskId);

        if ($task !== null) {
            $downloads->process($task);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(DownloadService::class)->failUnexpectedly($this->taskId, $exception);
    }
}
