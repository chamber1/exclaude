<?php

namespace App\Models;

use App\Downloads\DownloadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $uuid
 * @property ?int $user_id
 * @property ?string $guest_id
 * @property string $client_hash
 * @property string $platform
 * @property string $media_id
 * @property string $type audio|video
 * @property string $format mp3|m4a|mp4
 * @property ?string $bitrate
 * @property ?string $quality
 * @property DownloadStatus $status
 * @property int $progress
 * @property int $attempts
 * @property ?string $title
 * @property ?int $duration
 * @property ?string $file_path
 * @property ?string $file_name
 * @property ?int $file_size
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $expires_at
 * @property ?Carbon $downloaded_at
 */
class DownloadTask extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => DownloadStatus::class,
        'progress' => 'integer',
        'attempts' => 'integer',
        'duration' => 'integer',
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'progress' => 0,
        'attempts' => 0,
    ];

    /** HasUuids fills the public "uuid" column; the primary key stays numeric. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', DownloadStatus::active());
    }

    /** Directory of the task's files, relative to the "local" disk. */
    public function directory(): string
    {
        return config('downloads.directory').'/'.$this->uuid;
    }

    public function absoluteDirectory(): string
    {
        return Storage::disk('local')->path($this->directory());
    }

    public function absoluteFilePath(): ?string
    {
        return $this->file_path !== null ? $this->absoluteDirectory().'/'.$this->file_path : null;
    }

    public function isDownloadable(): bool
    {
        return $this->status === DownloadStatus::Completed
            && $this->expires_at?->isFuture()
            && is_file((string) $this->absoluteFilePath());
    }
}
