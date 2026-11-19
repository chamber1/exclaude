<?php

namespace App\Downloads;

use App\Models\DownloadTask;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Periodic housekeeping (`downloads:cleanup`, every 5 minutes from the scheduler):
 * expired files, stuck tasks, orphaned directories, old rows.
 */
final class DownloadCleaner
{
    /** Directories younger than this are left alone: their task may have just been claimed. */
    private const ORPHAN_GRACE = 600;

    /** A file handed to nginx (X-Accel-Redirect) is kept this long after the download started. */
    public const DELIVERY_GRACE = 1800;

    public function __construct(private readonly DownloadService $downloads) {}

    /** @return array{expired: int, stuck: int, orphans: int, pruned: int} */
    public function run(): array
    {
        return [
            'expired' => $this->expireFiles(),
            'stuck' => $this->failStuckTasks(),
            'orphans' => $this->removeOrphans(),
            'pruned' => $this->pruneOldTasks(),
        ];
    }

    public function expireFiles(): int
    {
        $count = 0;
        DownloadTask::query()
            ->where('status', DownloadStatus::Completed)
            ->where('expires_at', '<=', now())
            ->each(function (DownloadTask $task) use (&$count) {
                $this->downloads->expire($task, 'ttl');
                $count++;
            });

        return $count;
    }

    /** Pending/processing tasks with no update (progress, status) for downloads.stuck_after seconds. */
    public function failStuckTasks(): int
    {
        $count = 0;
        DownloadTask::query()
            ->active()
            ->where('updated_at', '<', now()->subSeconds((int) config('downloads.stuck_after')))
            ->each(function (DownloadTask $task) use (&$count) {
                $this->downloads->fail($task, 'stuck', 'Загрузка прервалась. Попробуйте ещё раз.');
                $count++;
            });

        return $count;
    }

    /** Task directories that no live task owns, plus leftovers of the old synchronous parser. */
    public function removeOrphans(): int
    {
        $disk = Storage::disk('local');
        $count = 0;
        $threshold = time() - self::ORPHAN_GRACE;

        $directories = $disk->directories((string) config('downloads.directory'));
        $live = DownloadTask::query()
            ->whereIn('uuid', array_map('basename', $directories))
            ->where(fn ($q) => $q
                ->whereIn('status', [DownloadStatus::Processing, DownloadStatus::Completed])
                // Just downloaded through nginx: it may still be sending the file.
                ->orWhere('downloaded_at', '>', now()->subSeconds(self::DELIVERY_GRACE)))
            ->pluck('uuid')
            ->all();

        foreach ($directories as $directory) {
            $path = $disk->path($directory);
            if (! in_array(basename($directory), $live, true) && filemtime($path) < $threshold) {
                File::deleteDirectory($path);
                $count++;
            }
        }

        // storage/app/parser: files of the previous synchronous implementation.
        foreach ($disk->files('parser') as $file) {
            if ($disk->lastModified($file) < $threshold && Str::isUuid(Str::before(basename($file), '.'))) {
                $disk->delete($file);
                $count++;
            }
        }

        return $count;
    }

    /** Finished rows are kept for support and statistics, then deleted (data minimisation). */
    public function pruneOldTasks(): int
    {
        return DownloadTask::query()
            ->whereIn('status', [DownloadStatus::Failed, DownloadStatus::Expired])
            ->where('updated_at', '<', now()->subDays((int) config('downloads.retention_days')))
            ->delete();
    }
}
