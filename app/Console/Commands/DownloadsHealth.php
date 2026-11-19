<?php

namespace App\Console\Commands;

use App\Downloads\DownloadMonitor;
use Illuminate\Console\Command;

/**
 * Exit code 0 = healthy. Used as the Docker healthcheck of the app, queue-worker and scheduler containers.
 */
class DownloadsHealth extends Command
{
    protected $signature = 'downloads:health {component : app, worker or scheduler}';

    protected $description = 'Health check for Docker (exit code 0 = healthy)';

    public function handle(DownloadMonitor $monitor): int
    {
        $healthy = match ($this->argument('component')) {
            'app' => ! in_array(false, $monitor->appChecks(), true),
            'worker' => $monitor->workerHealthy(),
            'scheduler' => $monitor->schedulerHealthy(),
            default => null,
        };

        if ($healthy === null) {
            $this->error('Unknown component.');

            return self::INVALID;
        }

        $this->line($healthy ? 'healthy' : 'unhealthy');

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
