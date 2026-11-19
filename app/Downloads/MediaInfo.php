<?php

namespace App\Downloads;

/** Public metadata of a video, as reported by yt-dlp (--dump-single-json). */
final class MediaInfo
{
    /** @param  list<int>  $heights  available video heights, descending */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?int $duration,
        public readonly ?string $uploader,
        public readonly ?string $thumbnail,
        public readonly ?string $license,
        public readonly bool $isLive,
        public readonly array $heights,
    ) {
    }

    public static function fromYtDlp(array $json): self
    {
        $heights = [];
        foreach ($json['formats'] ?? [] as $format) {
            if (($format['vcodec'] ?? 'none') !== 'none' && is_int($format['height'] ?? null) && $format['height'] > 0) {
                $heights[$format['height']] = true;
            }
        }
        krsort($heights);

        $thumbnail = $json['thumbnail'] ?? null;

        return new self(
            id: (string) ($json['id'] ?? ''),
            title: trim((string) ($json['title'] ?? '')) ?: (string) ($json['id'] ?? ''),
            duration: isset($json['duration']) ? (int) round((float) $json['duration']) : null,
            uploader: $json['uploader'] ?? $json['channel'] ?? null,
            // Only https thumbnails are shown on the page.
            thumbnail: is_string($thumbnail) && str_starts_with($thumbnail, 'https://') ? $thumbnail : null,
            license: isset($json['license']) && $json['license'] !== '' ? (string) $json['license'] : null,
            isLive: (bool) ($json['is_live'] ?? false) || in_array($json['live_status'] ?? null, ['is_live', 'is_upcoming'], true),
            heights: array_keys($heights),
        );
    }

    public function isCreativeCommons(): bool
    {
        return $this->license !== null && stripos($this->license, 'creative commons') !== false;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
