<?php

namespace App\Downloads;

use App\Downloads\Exceptions\DownloadRejected;
use App\Downloads\Platforms\Platform;
use App\Downloads\Platforms\YouTube;

/**
 * Turns a user-supplied link into a MediaReference, or rejects it.
 *
 * First SSRF barrier: only http(s) links to explicitly listed hosts on the default port and
 * without credentials are accepted. IP literals (127.0.0.1, [::1], 2130706433, 0x7f.1),
 * internal names (mysql, redis, localhost), look-alikes (youtube.com.evil.test) and URL
 * shorteners never get through, so there is no redirect to follow. The user's string itself
 * is never passed on: yt-dlp receives the canonical URL rebuilt from the extracted id.
 */
final class UrlParser
{
    /** @var list<Platform> */
    private array $platforms;

    /** @param  list<Platform>|null  $platforms */
    public function __construct(?array $platforms = null)
    {
        $this->platforms = $platforms ?? [new YouTube];
    }

    public function parse(string $input): MediaReference
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 2048 || preg_match('/[\x00-\x20\x7F]/', $input)) {
            throw DownloadRejected::invalidUrl();
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        if ($parts === false || ! isset($parts['host'])) {
            throw DownloadRejected::invalidUrl();
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? null;
        $host = rtrim(strtolower($parts['host']), '.');

        if (! in_array($scheme, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || ($port !== null && ! in_array($port, [80, 443], true))
            || NetworkGuard::isIpLiteral($host)) {
            throw DownloadRejected::invalidUrl();
        }

        parse_str($parts['query'] ?? '', $query);
        $url = ['host' => $host, 'path' => $parts['path'] ?? '', 'query' => $query];

        foreach ($this->platforms as $platform) {
            if (in_array($host, $platform->hosts(), true)) {
                $id = $platform->mediaId($url);

                return $id !== null ? new MediaReference($platform, $id) : throw DownloadRejected::invalidUrl();
            }
        }

        throw DownloadRejected::unsupportedSite();
    }

    /** Rebuilds the reference of a stored task (download_tasks.platform + media_id). */
    public function reference(string $platform, string $mediaId): MediaReference
    {
        foreach ($this->platforms as $candidate) {
            if ($candidate->name() === $platform) {
                return new MediaReference($candidate, $mediaId);
            }
        }

        throw DownloadRejected::unsupportedSite();
    }
}
