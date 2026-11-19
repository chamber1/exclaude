<?php

namespace Tests\Unit\Downloads;

use App\Downloads\Exceptions\DownloadRejected;
use App\Downloads\UrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlParserTest extends TestCase
{
    public static function validLinks(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=jNQXAC9IVRw'],
            'watch with extra params' => ['https://www.youtube.com/watch?list=PL1&v=jNQXAC9IVRw&t=10s'],
            'no scheme' => ['youtube.com/watch?v=jNQXAC9IVRw'],
            'http' => ['http://youtube.com/watch?v=jNQXAC9IVRw'],
            'mobile' => ['https://m.youtube.com/watch?v=jNQXAC9IVRw'],
            'music' => ['https://music.youtube.com/watch?v=jNQXAC9IVRw'],
            'short link' => ['https://youtu.be/jNQXAC9IVRw?si=abc'],
            'shorts' => ['https://www.youtube.com/shorts/jNQXAC9IVRw'],
            'embed' => ['https://www.youtube.com/embed/jNQXAC9IVRw'],
            'live' => ['https://www.youtube.com/live/jNQXAC9IVRw'],
            'upper case host, trailing dot, port 443' => ['https://WWW.YOUTUBE.COM.:443/watch?v=jNQXAC9IVRw'],
            'surrounding spaces' => ['  https://youtu.be/jNQXAC9IVRw  '],
        ];
    }

    #[DataProvider('validLinks')]
    public function test_valid_links_become_canonical_urls(string $link): void
    {
        $media = (new UrlParser)->parse($link);

        $this->assertSame('youtube', $media->platform->name());
        $this->assertSame('jNQXAC9IVRw', $media->id);
        $this->assertSame('https://www.youtube.com/watch?v=jNQXAC9IVRw', $media->url());
        $this->assertSame('www.youtube.com', $media->host());
    }

    public static function invalidLinks(): array
    {
        return [
            'empty' => ['', 'invalid_url'],
            'garbage' => ['not a link', 'invalid_url'],
            'too long' => ['https://youtu.be/jNQXAC9IVRw?x='.str_repeat('a', 2100), 'invalid_url'],
            'newline' => ["https://youtu.be/jNQXAC9IVRw\nHost: evil", 'invalid_url'],
            'file scheme' => ['file:///etc/passwd', 'invalid_url'],
            'gopher' => ['gopher://www.youtube.com/watch?v=jNQXAC9IVRw', 'invalid_url'],
            'javascript' => ['javascript:alert(1)', 'invalid_url'],
            'credentials' => ['https://user:pass@www.youtube.com/watch?v=jNQXAC9IVRw', 'invalid_url'],
            'user only' => ['https://www.youtube.com@evil.test/watch?v=jNQXAC9IVRw', 'invalid_url'],
            'other port' => ['https://www.youtube.com:8080/watch?v=jNQXAC9IVRw', 'invalid_url'],
            'loopback' => ['http://127.0.0.1/watch?v=jNQXAC9IVRw', 'invalid_url'],
            'metadata service' => ['http://169.254.169.254/latest/meta-data', 'invalid_url'],
            'decimal ip' => ['http://2130706433/', 'invalid_url'],
            'hex ip' => ['http://0x7f000001/', 'invalid_url'],
            'ipv6 loopback' => ['http://[::1]/', 'invalid_url'],
            'internal service on its port' => ['http://mysql:3306/', 'invalid_url'],
            'internal service name' => ['http://redis/', 'unsupported_site'],
            'localhost' => ['http://localhost/watch?v=jNQXAC9IVRw', 'unsupported_site'],
            'look-alike host' => ['https://youtube.com.evil.test/watch?v=jNQXAC9IVRw', 'unsupported_site'],
            'subdomain not listed' => ['https://evil.youtube.com/watch?v=jNQXAC9IVRw', 'unsupported_site'],
            'shortener' => ['https://bit.ly/3abcdef', 'unsupported_site'],
            'no video id' => ['https://www.youtube.com/', 'invalid_url'],
            'channel' => ['https://www.youtube.com/@jawed', 'invalid_url'],
            'id too short' => ['https://youtu.be/abc', 'invalid_url'],
            'id with bad chars' => ['https://www.youtube.com/watch?v=jNQXAC9IV%2F', 'invalid_url'],
            'id as array' => ['https://www.youtube.com/watch?v[]=jNQXAC9IVRw', 'invalid_url'],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_links_are_rejected(string $link, string $reason): void
    {
        try {
            (new UrlParser)->parse($link);
            $this->fail('Accepted: '.$link);
        } catch (DownloadRejected $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_reference_rebuilds_a_stored_task(): void
    {
        $media = (new UrlParser)->reference('youtube', 'jNQXAC9IVRw');

        $this->assertSame('https://www.youtube.com/watch?v=jNQXAC9IVRw', $media->url());
    }

    public function test_reference_of_an_unknown_platform_is_rejected(): void
    {
        $this->expectException(DownloadRejected::class);

        (new UrlParser)->reference('vimeo', '123');
    }
}
