<?php

namespace Tests\Unit\Downloads;

use App\Downloads\ProgressTracker;
use App\Downloads\YtDlp;
use PHPUnit\Framework\TestCase;

class ProgressTrackerTest extends TestCase
{
    private static function progress(int $done, ?int $total, int $estimate = null): string
    {
        return YtDlp::PROGRESS_MARKER.$done.':'.($total ?? 'NA').':'.($estimate ?? 'NA')."\n";
    }

    public function test_single_stream_maps_to_0_90_then_95_on_post_processing(): void
    {
        $t = new ProgressTracker();

        $this->assertNull($t->feed(YtDlp::FORMAT_MARKER."140\n"));
        $t->feed("[download] Destination: /x/media.webm\n");
        $this->assertSame(45, $t->feed(self::progress(50, 100)));
        $this->assertSame(90, $t->feed(self::progress(100, 100)));
        $this->assertSame(95, $t->feed("[ExtractAudio] Destination: /x/media.mp3\n"));
    }

    public function test_two_streams_share_the_range(): void
    {
        $t = new ProgressTracker();
        $t->feed(YtDlp::FORMAT_MARKER."137+140\n", 'out');

        $t->feed("[download] Destination: /x/media.f137.mp4\n", 'err');
        $this->assertSame(45, $t->feed(self::progress(1000, 1000), 'err'));

        $t->feed("[download] Destination: /x/media.f140.m4a\n", 'err');
        $this->assertSame(67, $t->feed(self::progress(50, 100), 'err'));
        $this->assertSame(95, $t->feed("[Merger] Merging formats into \"/x/media.mp4\"\n", 'err'));
    }

    public function test_estimate_is_used_when_total_is_unknown(): void
    {
        $t = new ProgressTracker();
        $t->feed("[download] Destination: /x/a\n");

        $this->assertSame(22, $t->feed(self::progress(25, null, 100)));
        $this->assertNull($t->feed(self::progress(25, null, null)), 'No size: nothing to report');
    }

    public function test_never_goes_backwards_and_never_reaches_100(): void
    {
        $t = new ProgressTracker();
        $t->feed("[download] Destination: /x/a\n");
        $t->feed(self::progress(80, 100));

        $this->assertNull($t->feed(self::progress(10, 100)));
        $this->assertSame(72, $t->percent());

        $t->feed("[Merger] x\n");
        $t->feed(self::progress(1000, 10));
        $this->assertSame(95, $t->percent());
    }

    public function test_lines_split_across_chunks_and_streams(): void
    {
        $t = new ProgressTracker();
        $t->feed("[download] Destination: /x/a\n", 'err');

        $this->assertNull($t->feed(substr(self::progress(50, 100), 0, 20), 'err'));
        // A complete line on stdout does not glue onto the unfinished stderr line.
        $t->feed(YtDlp::TITLE_MARKER."title\n", 'out');
        $this->assertSame(45, $t->feed(substr(self::progress(50, 100), 20), 'err'));
    }

    public function test_windows_line_endings_and_noise_are_ignored(): void
    {
        $t = new ProgressTracker();
        $t->feed("[youtube] jNQXAC9IVRw: Downloading webpage\r\n[download] Destination: a\r\n");

        $this->assertSame(9, $t->feed(rtrim(self::progress(10, 100))."\r\n"));
        $this->assertNull($t->feed("WARNING: something\n"));
    }
}
