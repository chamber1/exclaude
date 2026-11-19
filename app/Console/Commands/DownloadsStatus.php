<?php

namespace App\Console\Commands;

use App\Downloads\DownloadMonitor;
use Illuminate\Console\Command;

class DownloadsStatus extends Command
{
    protected $signature = 'downloads:status {--json : Machine-readable output}';

    protected $description = 'Show download tasks, queue length, failed jobs, workers and disk usage';

    public function handle(DownloadMonitor $monitor): int
    {
        $s = $monitor->snapshot();

        if ($this->option('json')) {
            $this->line(json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=green;options=bold>Tasks</>');
        foreach (['pending', 'processing', 'completed', 'failed', 'expired'] as $status) {
            $this->components->twoColumnDetail($status, (string) ($s['tasks'][$status] ?? 0));
        }
        $this->components->twoColumnDetail('oldest pending', $s['oldest_pending_seconds'] === null ? '—' : $s['oldest_pending_seconds'].' s');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Queue</>');
        $this->components->twoColumnDetail('connection / queue', $s['queue']['connection'].' / '.$s['queue']['name']);
        $this->components->twoColumnDetail('jobs waiting', (string) ($s['queue']['size'] ?? 'n/a'));
        $this->components->twoColumnDetail('failed jobs', (string) ($s['queue']['failed_jobs'] ?? 'n/a'));
        $this->components->twoColumnDetail('scheduler', $s['scheduler_healthy'] ? '<fg=green>OK</>' : '<fg=red>NO HEARTBEAT</>');
        foreach ($s['workers'] as $host => $w) {
            $state = $w['healthy'] ? '<fg=green>OK</>' : '<fg=red>STALE</>';
            $this->components->twoColumnDetail("worker {$host}", $state.($w['busy'] ? ' (busy)' : '').', seen '.$w['last_seen_seconds_ago'].' s ago');
        }
        if ($s['workers'] === []) {
            $this->components->twoColumnDetail('workers', '<fg=red>none seen</>');
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Disk</>');
        $this->components->twoColumnDetail('downloads', round($s['disk']['downloads_bytes'] / 1048576, 1).' MB');
        $this->components->twoColumnDetail('free', $s['disk']['free_bytes'] ? round($s['disk']['free_bytes'] / 1073741824, 1).' GB' : 'n/a');

        return self::SUCCESS;
    }
}
