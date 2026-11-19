<?php

namespace App\Downloads\Platforms;

/**
 * A video platform the service explicitly supports. Adding a platform means adding a class
 * here and listing it in UrlParser; arbitrary URLs are never handed to yt-dlp.
 */
interface Platform
{
    /** Short stable name stored in download_tasks.platform. */
    public function name(): string;

    /** @return list<string> exact (lower-case) host names accepted in user links */
    public function hosts(): array;

    /** yt-dlp extractor(s) allowed for this platform (passed to --use-extractors). */
    public function extractors(): string;

    /**
     * Extracts the media id from a URL already checked to be on one of hosts().
     *
     * @param  array{host: string, path: string, query: array<string, mixed>}  $url
     */
    public function mediaId(array $url): ?string;

    /** Canonical URL built from the id only; this, not the user's input, goes to yt-dlp. */
    public function canonicalUrl(string $mediaId): string;
}
