<?php

namespace App\Console\Commands;

use App\Downloads\DownloadCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DownloadsCleanup extends Command
{
    protected $signature = 'downloads:cleanup';

    protected $description = 'Delete expired download files, fail stuck tasks, remove orphaned files and old task rows';

    public function handle(DownloadCleaner $cleaner): int
    {
        $stats = $cleaner->run();

        if (array_sum($stats) > 0) {
            Log::channel('downloads')->info('cleanup', $stats);
        }
        $this->components->info(collect($stats)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
