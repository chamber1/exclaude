<?php

namespace Tests\Feature\Downloads;

use App\Downloads\DownloadStatus;
use App\Jobs\DownloadJob;
use App\Models\DownloadTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class DownloadJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<list<string>> commands yt-dlp was started with */
    private array $commands = [];

    private function runJob(DownloadTask $task): DownloadTask
    {
        app()->call([new DownloadJob($task->id), 'handle']);

        return $task->fresh();
    }

    /** Records commands, then behaves like the default fake (or $then). */
    private function recordCommands(?\Closure $then = null): void
    {
        $this->fakeYtDlp(download: function (array $command) use ($then) {
            $this->commands[] = $command;
            if ($then !== null) {
                return $then($command);
            }
            $template = $command[array_search('-o', $command, true) + 1];
            $ext = in_array('--merge-output-format', $command, true) ? 'mp4' : $command[array_search('--audio-format', $command, true) + 1];
            $file = str_replace('%(ext)s', $ext, $template);
            file_put_contents($file, 'fake media');

            return Process::result("PARSER_FILE:{$file}\nPARSER_TITLE:Me at the zoo\n");
        });
    }

    public function test_audio_task_completes(): void
    {
        $this->recordCommands();
        $task = $this->runJob(DownloadTask::factory()->create());

        $this->assertSame(DownloadStatus::Completed, $task->status);
        $this->assertSame(100, $task->progress);
        $this->assertSame(1, $task->attempts);
        $this->assertSame('media.mp3', $task->file_path);
        $this->assertSame('Me at the zoo (128 kbps).mp3', $task->file_name);
        $this->assertSame(10, $task->file_size);
        $this->assertTrue($task->expires_at->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertTrue($task->isDownloadable());
    }

    public function test_command_is_an_argument_array_with_safe_options(): void
    {
        $this->recordCommands();
        $this->runJob(DownloadTask::factory()->video('720')->create());

        $command = $this->commands[0];
        $this->assertSame(config('downloads.yt_dlp.binary'), $command[0]);
        foreach (['--ignore-config', '--no-playlist', '--no-cache-dir'] as $option) {
            $this->assertContains($option, $command);
        }
        $this->assertSame('youtube', $command[array_search('--use-extractors', $command, true) + 1]);
        $this->assertSame('duration <= 3600 & !is_live', $command[array_search('--match-filter', $command, true) + 1]);
        $this->assertSame('500M', $command[array_search('--max-filesize', $command, true) + 1], 'guest plan limit');
        $this->assertSame('res:720,vcodec:h264,acodec:aac', $command[array_search('-S', $command, true) + 1]);
        // The URL is rebuilt from the id and comes last, after "--".
        $this->assertSame(['--', 'https://www.youtube.com/watch?v=jNQXAC9IVRw'], array_slice($command, -2));
    }

    public function test_plan_limits_and_licence_policy_reach_the_command(): void
    {
        config(['downloads.license_policy' => 'creative_commons']);
        $this->recordCommands();
        $user = User::factory()->create();
        $user->forceFill(['plan' => 'premium'])->save();

        $this->runJob(DownloadTask::factory()->forUser($user)->create());

        $command = $this->commands[0];
        $this->assertSame('4G', $command[array_search('--max-filesize', $command, true) + 1]);
        $this->assertStringContainsString("license ~= '(?i)creative commons'", $command[array_search('--match-filter', $command, true) + 1]);
    }

    public function test_known_yt_dlp_error_fails_the_task_with_a_friendly_message(): void
    {
        $this->recordCommands(fn () => Process::result('', "ERROR: [youtube] jNQXAC9IVRw: Private video\n", 1));
        $task = $this->runJob(DownloadTask::factory()->create());

        $this->assertSame(DownloadStatus::Failed, $task->status);
        $this->assertSame('private', $task->error_code);
        $this->assertSame('Это приватное видео.', $task->error_message);
        $this->assertDirectoryDoesNotExist($task->absoluteDirectory());

        $this->getJson('/downloads/'.$task->uuid)->assertNotFound(); // other visitor
    }

    public function test_skipped_by_filter_counts_as_failure(): void
    {
        // yt-dlp exits 0 but downloads nothing when the match filter does not pass.
        $this->recordCommands(fn () => Process::result("[download] x does not pass filter (duration <= 3600 & !is_live), skipping ..\n"));
        $task = $this->runJob(DownloadTask::factory()->create());

        $this->assertSame(DownloadStatus::Failed, $task->status);
        $this->assertSame('filtered', $task->error_code);
    }

    public function test_file_outside_the_task_directory_is_not_accepted(): void
    {
        $outside = storage_path('framework/testing/outside.mp3');
        File::ensureDirectoryExists(dirname($outside));
        file_put_contents($outside, 'x');
        $this->recordCommands(fn () => Process::result("PARSER_FILE:{$outside}\n"));

        $task = $this->runJob(DownloadTask::factory()->create());

        $this->assertSame(DownloadStatus::Failed, $task->status);
        $this->assertNull($task->file_path);
        @unlink($outside);
    }

    public function test_timeout_fails_the_task(): void
    {
        $this->recordCommands(function () {
            throw new ProcessTimedOutException(new SymfonyTimedOut(new SymfonyProcess(['yt-dlp']), SymfonyTimedOut::TYPE_GENERAL), Process::result('', 'partial'));
        });
        $task = $this->runJob(DownloadTask::factory()->create());

        $this->assertSame(DownloadStatus::Failed, $task->status);
        $this->assertSame('timeout', $task->error_code);
    }

    public function test_unexpected_exception_is_rethrown_for_a_retry_and_partial_files_are_removed(): void
    {
        $task = DownloadTask::factory()->create();
        $this->recordCommands(function (array $command) {
            file_put_contents(str_replace('%(ext)s', 'part', $command[array_search('-o', $command, true) + 1]), 'x');

            throw new RuntimeException('worker lost the database');
        });

        try {
            $this->runJob($task);
            $this->fail('Exception expected');
        } catch (RuntimeException) {
        }

        $this->assertSame(DownloadStatus::Processing, $task->fresh()->status);
        $this->assertDirectoryDoesNotExist($task->absoluteDirectory());
    }

    public function test_task_left_processing_by_a_crashed_worker_is_started_over(): void
    {
        $this->recordCommands();
        $task = DownloadTask::factory()->status(DownloadStatus::Processing)->create(['attempts' => 1, 'progress' => 40]);
        File::ensureDirectoryExists($task->absoluteDirectory());
        file_put_contents($task->absoluteDirectory().'/media.webm.part', 'stale');

        $task = $this->runJob($task);

        $this->assertSame(DownloadStatus::Completed, $task->status);
        $this->assertSame(2, $task->attempts);
        $this->assertFileDoesNotExist($task->absoluteDirectory().'/media.webm.part');
    }

    public function test_finished_task_is_not_processed_again(): void
    {
        $this->recordCommands();
        $task = DownloadTask::factory()->completed()->create();

        $this->runJob($task);
        $this->runJob(DownloadTask::factory()->status(DownloadStatus::Failed)->create());

        $this->assertSame([], $this->commands);
        Process::assertNothingRan();
    }

    public function test_job_failed_handler_marks_the_task_failed(): void
    {
        $task = DownloadTask::factory()->status(DownloadStatus::Processing)->create();

        (new DownloadJob($task->id))->failed(new RuntimeException('Job has timed out'));

        $task->refresh();
        $this->assertSame(DownloadStatus::Failed, $task->status);
        $this->assertSame('server_error', $task->error_code);
        $this->assertStringNotContainsString('timed out', $task->error_message);
    }

    public function test_job_failed_handler_leaves_finished_tasks_alone(): void
    {
        $task = DownloadTask::factory()->completed()->create();

        (new DownloadJob($task->id))->failed(null);

        $this->assertSame(DownloadStatus::Completed, $task->fresh()->status);
    }

    public function test_job_settings(): void
    {
        $job = new DownloadJob(1);

        $this->assertSame('downloads', $job->queue);
        $this->assertSame(2, $job->tries);
        $this->assertSame(660, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $job->timeout);
        $this->assertSame('1', $job->uniqueId());
    }
}
