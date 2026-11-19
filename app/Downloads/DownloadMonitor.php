<?php

namespace App\Downloads;

use App\Models\DownloadTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Health and queue state: worker/scheduler heartbeats, queue length, failed jobs, disk usage.
 * Used by `downloads:status`, `downloads:health` (Docker healthchecks) and GET /up.
 */
final class DownloadMonitor
{
    /** A worker that has not polled the queue for this long (and is not inside a job) is unhealthy. */
    public const WORKER_STALE_AFTER = 120;

    /** The scheduler writes its heartbeat every minute. */
    public const SCHEDULER_STALE_AFTER = 180;

    private const WORKERS_KEY = 'downloads:monitor:workers';

    public static function workerKey(?string $host = null): string
    {
        return 'downloads:monitor:worker:'.($host ?? gethostname());
    }

    /** Called from the queue's "looping" event; throttled so a polling worker does not hammer the cache. */
    public static function workerHeartbeat(): void
    {
        static $last = 0;
        if (time() - $last < 10) {
            return;
        }
        $last = time();

        Cache::put(self::workerKey(), ['seen' => time(), 'busy_until' => 0], 3600);
        $workers = Cache::get(self::WORKERS_KEY, []);
        if (! in_array(gethostname(), $workers, true)) {
            Cache::forever(self::WORKERS_KEY, array_values(array_unique([...$workers, gethostname()])));
        }
    }

    /** A worker inside a long job does not poll, so it announces how long it may legitimately be silent. */
    public static function workerBusy(int $seconds): void
    {
        Cache::put(self::workerKey(), ['seen' => time(), 'busy_until' => time() + $seconds], 3600);
    }

    public static function schedulerHeartbeat(): void
    {
        Cache::put('downloads:monitor:scheduler', time(), 3600);
    }

    public function workerHealthy(?string $host = null): bool
    {
        $state = Cache::get(self::workerKey($host));

        return is_array($state) && (time() - $state['seen'] < self::WORKER_STALE_AFTER || time() < $state['busy_until']);
    }

    public function schedulerHealthy(): bool
    {
        return time() - (int) Cache::get('downloads:monitor:scheduler', 0) < self::SCHEDULER_STALE_AFTER;
    }

    /** @return array<string, bool> */
    public function appChecks(): array
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = true;
        } catch (Throwable) {
            $checks['database'] = false;
        }

        try {
            // A key per probe: concurrent health checks (nginx, Docker, monitoring) must not overwrite
            // each other. A non-numeric value: Redis returns numbers as strings.
            $token = 'ok-'.bin2hex(random_bytes(8));
            $key = 'downloads:monitor:probe:'.$token;
            Cache::put($key, $token, 10);
            $checks['cache'] = Cache::get($key) === $token;
            Cache::forget($key);
        } catch (Throwable) {
            $checks['cache'] = false;
        }

        $checks['storage'] = is_writable(Storage::disk('local')->path(''));

        return $checks;
    }

    public function snapshot(): array
    {
        $queue = (string) config('downloads.queue');
        $directory = Storage::disk('local')->path((string) config('downloads.directory'));

        $workers = [];
        foreach (Cache::get(self::WORKERS_KEY, []) as $host) {
            $state = Cache::get(self::workerKey($host));
            $workers[$host] = [
                'healthy' => $this->workerHealthy($host),
                'last_seen_seconds_ago' => is_array($state) ? time() - $state['seen'] : null,
                'busy' => is_array($state) && time() < $state['busy_until'],
            ];
        }

        return [
            'tasks' => DownloadTask::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'oldest_pending_seconds' => ($oldest = DownloadTask::query()->where('status', DownloadStatus::Pending)->min('created_at'))
                ? (int) abs(now()->diffInSeconds($oldest)) : null,
            'queue' => [
                'name' => $queue,
                'connection' => config('queue.default'),
                'size' => $this->safe(fn () => Queue::size($queue)),
                'failed_jobs' => $this->safe(fn () => DB::table('failed_jobs')->count()),
            ],
            'workers' => $workers,
            'scheduler_healthy' => $this->schedulerHealthy(),
            'disk' => [
                'downloads_bytes' => $this->directorySize($directory),
                'free_bytes' => @disk_free_space(Storage::disk('local')->path('')) ?: null,
            ],
        ];
    }

    private function directorySize(string $directory): int
    {
        if (! is_dir($directory)) {
            return 0;
        }
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
