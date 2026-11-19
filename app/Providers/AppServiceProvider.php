<?php

namespace App\Providers;

use App\Downloads\DownloadMonitor;
use App\Downloads\NetworkGuard;
use App\Downloads\UrlParser;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(UrlParser::class);
        $this->app->singleton(NetworkGuard::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Worker heartbeats for `downloads:health worker` and `downloads:status`.
        Queue::looping(fn () => DownloadMonitor::workerHeartbeat());
        Queue::before(fn () => DownloadMonitor::workerBusy((int) config('downloads.yt_dlp.timeout') + 120));
        Queue::after(fn () => DownloadMonitor::workerBusy(0));
        Queue::failing(fn () => DownloadMonitor::workerBusy(0));

        // Raised by `queue:monitor` (scheduled) when the downloads queue grows too long.
        Event::listen(QueueBusy::class, function (QueueBusy $event) {
            Log::channel('downloads')->warning('queue is busy', [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });
    }
}
