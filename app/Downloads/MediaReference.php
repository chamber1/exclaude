<?php

namespace App\Downloads;

use App\Downloads\Platforms\Platform;

/** A validated pointer to one video on a supported platform. */
final class MediaReference
{
    public function __construct(
        public readonly Platform $platform,
        public readonly string $id,
    ) {
    }

    public function url(): string
    {
        return $this->platform->canonicalUrl($this->id);
    }

    public function host(): string
    {
        return (string) parse_url($this->url(), PHP_URL_HOST);
    }

    public function cacheKey(): string
    {
        return 'downloads:info:'.$this->platform->name().':'.$this->id;
    }
}
