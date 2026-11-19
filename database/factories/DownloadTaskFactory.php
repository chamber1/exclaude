<?php

namespace Database\Factories;

use App\Downloads\DownloadOwner;
use App\Downloads\DownloadStatus;
use App\Models\DownloadTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;

/**
 * @extends Factory<DownloadTask>
 */
class DownloadTaskFactory extends Factory
{
    protected $model = DownloadTask::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'guest_id' => DownloadOwner::hash('guest', 'factory'),
            'client_hash' => DownloadOwner::hash('ip', '203.0.113.10'),
            'platform' => 'youtube',
            'media_id' => 'jNQXAC9IVRw',
            'type' => 'audio',
            'format' => 'mp3',
            'bitrate' => '128',
            'quality' => null,
            'status' => DownloadStatus::Pending,
            'title' => 'Me at the zoo',
            'duration' => 19,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id, 'guest_id' => null]);
    }

    public function video(string $quality = '720'): static
    {
        return $this->state(['type' => 'video', 'format' => 'mp4', 'bitrate' => null, 'quality' => $quality]);
    }

    public function status(DownloadStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /** A finished task with a real file on the local disk. */
    public function completed(string $contents = 'fake media'): static
    {
        return $this->state([
            'status' => DownloadStatus::Completed,
            'progress' => 100,
            'file_path' => 'media.mp3',
            'file_name' => 'Me at the zoo (128 kbps).mp3',
            'file_size' => strlen($contents),
            'completed_at' => now(),
            'expires_at' => now()->addHour(),
        ])->afterCreating(function (DownloadTask $task) use ($contents) {
            File::ensureDirectoryExists($task->absoluteDirectory());
            File::put((string) $task->absoluteFilePath(), $contents);
        });
    }
}
