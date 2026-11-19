<?php

namespace Tests\Unit\Downloads;

use App\Downloads\YtDlpErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YtDlpErrorsTest extends TestCase
{
    public static function outputs(): array
    {
        return [
            ['[download] Me at the zoo does not pass filter (duration <= 3600 & !is_live), skipping ..', 0, 'filtered'],
            ['File is larger than max-filesize (52428800 bytes > 524288000 bytes). Aborting.', 0, 'too_large'],
            ['ERROR: [youtube] abc: Private video. Sign in if you\'ve been granted access', 1, 'private'],
            ['ERROR: [youtube] abc: Sign in to confirm your age.', 1, 'age_restricted'],
            ['ERROR: [youtube] abc: Sign in to confirm you\'re not a bot.', 1, 'blocked'],
            ['ERROR: unable to download video data: HTTP Error 429: Too Many Requests', 1, 'blocked'],
            ['ERROR: [youtube] abc: Video unavailable', 1, 'unavailable'],
            ['ERROR: [youtube] abc: This live event will begin in 3 hours.', 1, 'live'],
            ['ERROR: [youtube] abc: Join this channel to get access to members-only content', 1, 'members_only'],
            ['ERROR: [youtube] abc: This video requires payment to watch.', 1, 'paid'],
            ['ERROR: [youtube] abc: This video is DRM protected', 1, 'drm'],
            ['ERROR: Postprocessing: ffmpeg not found. Please install', 1, 'server_error'],
            ['ERROR: unable to write data: [Errno 28] No space left on device', 1, 'server_error'],
            ['sh: yt-dlp: not found', 127, 'server_error'],
            ['ERROR: something completely new', 1, 'failed'],
        ];
    }

    #[DataProvider('outputs')]
    public function test_known_failures_get_a_reason(string $output, int $exit, string $reason): void
    {
        $e = YtDlpErrors::failure($output, $exit, 'звук');

        $this->assertSame($reason, $e->reason);
        // Raw tool output never reaches the user.
        $this->assertStringNotContainsString('ERROR', $e->getMessage());
        $this->assertStringNotContainsString('/', $e->getMessage());
    }

    public function test_unknown_failure_names_what_was_requested(): void
    {
        $this->assertSame('Не удалось скачать видео из этого ролика.', YtDlpErrors::failure('?', 1, 'видео')->getMessage());
    }

    public function test_timeout(): void
    {
        $this->assertSame('timeout', YtDlpErrors::timeout()->reason);
    }
}
