<?php

namespace App\Console;

use App\Downloads\DownloadMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule (run by the "scheduler" container: `schedule:work`).
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('downloads:cleanup')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

        // Logs a warning (see AppServiceProvider) when more than 50 downloads wait in the queue.
        $schedule->command('queue:monitor', ['redis:'.config('downloads.queue'), '--max' => 50])->everyFiveMinutes();

        $schedule->command('queue:prune-failed', ['--hours' => 24 * 7])->daily();

        $schedule->call(fn () => DownloadMonitor::schedulerHeartbeat())->everyMinute()->name('downloads:scheduler-heartbeat');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
