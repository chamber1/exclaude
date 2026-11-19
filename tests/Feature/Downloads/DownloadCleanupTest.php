<?php

namespace Tests\Feature\Downloads;

use App\Downloads\DownloadCleaner;
use App\Downloads\DownloadStatus;
use App\Models\DownloadTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DownloadCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function age(string $path, int $seconds): void
    {
        touch($path, time() - $seconds);
    }

    public function test_command_runs_and_reports(): void
    {
        $this->artisan('downloads:cleanup')
            ->expectsOutputToContain('expired: 0, stuck: 0, orphans: 0, pruned: 0')
            ->assertSuccessful();
    }

    public function test_expired_files_are_deleted(): void
    {
        $expired = DownloadTask::factory()->completed()->create();
        $fresh = DownloadTask::factory()->completed()->create();
        $expired->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(DownloadCleaner::class)->expireFiles());

        $this->assertSame(DownloadStatus::Expired, $expired->fresh()->status);
        $this->assertNull($expired->fresh()->file_path);
        $this->assertDirectoryDoesNotExist($expired->absoluteDirectory());
        $this->assertSame(DownloadStatus::Completed, $fresh->fresh()->status);
        $this->assertFileExists($fresh->absoluteFilePath());
    }

    public function test_stuck_tasks_are_failed(): void
    {
        $stuck = DownloadTask::factory()->status(DownloadStatus::Processing)->create();
        $waiting = DownloadTask::factory()->create();
        DownloadTask::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHour()]);

        $this->assertSame(1, app(DownloadCleaner::class)->failStuckTasks());

        $this->assertSame(DownloadStatus::Failed, $stuck->fresh()->status);
        $this->assertSame('stuck', $stuck->fresh()->error_code);
        $this->assertSame(DownloadStatus::Pending, $waiting->fresh()->status);
    }

    public function test_orphaned_directories_and_legacy_files_are_removed(): void
    {
        $disk = Storage::disk('local');
        $root = (string) config('downloads.directory');

        $orphan = $disk->path($root.'/'.Str::uuid());
        $young = $disk->path($root.'/'.Str::uuid());
        File::ensureDirectoryExists($orphan);
        File::ensureDirectoryExists($young);
        $this->age($orphan, 3600);

        $live = DownloadTask::factory()->completed()->create();
        $this->age($live->absoluteDirectory(), 3600);

        $justSent = DownloadTask::factory()->completed()->create();
        $justSent->update(['status' => DownloadStatus::Expired, 'downloaded_at' => now()->subMinutes(5)]);
        $this->age($justSent->absoluteDirectory(), 3600);

        $legacy = 'parser/'.Str::uuid().'.mp3';
        $disk->put($legacy, 'x');
        $this->age($disk->path($legacy), 3600);
        $disk->put('parser/.gitignore', '*');
        $this->age($disk->path('parser/.gitignore'), 3600);

        $this->assertSame(2, app(DownloadCleaner::class)->removeOrphans());

        $this->assertDirectoryDoesNotExist($orphan);
        $this->assertDirectoryExists($young, 'grace period');
        $this->assertDirectoryExists($live->absoluteDirectory());
        $this->assertDirectoryExists($justSent->absoluteDirectory(), 'nginx may still be sending it');
        $this->assertFalse($disk->exists($legacy));
        $this->assertTrue($disk->exists('parser/.gitignore'));

        // After the delivery grace period the file goes too.
        $justSent->update(['downloaded_at' => now()->subHour()]);
        $this->assertSame(1, app(DownloadCleaner::class)->removeOrphans());
        $this->assertDirectoryDoesNotExist($justSent->absoluteDirectory());
    }

    public function test_old_finished_rows_are_pruned(): void
    {
        $old = DownloadTask::factory()->status(DownloadStatus::Expired)->create();
        $recent = DownloadTask::factory()->status(DownloadStatus::Failed)->create();
        $active = DownloadTask::factory()->create();
        DownloadTask::query()->whereKey([$old->id, $active->id])->update(['updated_at' => now()->subDays(31)]);

        $this->assertSame(1, app(DownloadCleaner::class)->pruneOldTasks());

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($active);
    }

    public function test_cleanup_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('downloads:cleanup')
            ->expectsOutputToContain('queue:prune-failed')
            ->assertSuccessful();
    }
}
