<?php

namespace App\Http\Resources;

use App\Downloads\DownloadService;
use App\Downloads\DownloadStatus;
use App\Models\DownloadTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a task (status API). Internal fields (owner hashes, paths, attempts) are not exposed.
 *
 * @mixin DownloadTask
 */
class DownloadTaskResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $url = app(DownloadService::class)->downloadUrl($this->resource);

        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'type' => $this->type,
            'format' => $this->format,
            'bitrate' => $this->bitrate,
            'quality' => $this->quality,
            'title' => $this->title,
            'duration' => $this->duration,
            'error' => $this->status === DownloadStatus::Failed ? $this->error_message : null,
            'error_code' => $this->status === DownloadStatus::Failed ? $this->error_code : null,
            'file' => $url === null ? null : [
                'name' => $this->file_name,
                'size' => $this->file_size,
                'url' => $url,
            ],
            'downloaded' => $this->downloaded_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status_url' => route('downloads.show', $this->uuid, false),
        ];
    }
}
