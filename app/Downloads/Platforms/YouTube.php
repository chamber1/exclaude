<?php

namespace App\Downloads\Platforms;

final class YouTube implements Platform
{
    public function name(): string
    {
        return 'youtube';
    }

    public function hosts(): array
    {
        return ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'];
    }

    public function extractors(): string
    {
        // Only the single-video extractor: no "generic" (arbitrary URLs), no playlists or channels.
        return 'youtube';
    }

    public function mediaId(array $url): ?string
    {
        $id = null;

        if ($url['host'] === 'youtu.be') {
            $id = explode('/', ltrim($url['path'], '/'))[0];
        } elseif ($url['path'] === '/watch') {
            $id = $url['query']['v'] ?? null;
        } elseif (preg_match('#^/(shorts|embed|live)/([^/]+)#', $url['path'], $m)) {
            $id = $m[2];
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }

    public function canonicalUrl(string $mediaId): string
    {
        return 'https://www.youtube.com/watch?v='.$mediaId;
    }
}
