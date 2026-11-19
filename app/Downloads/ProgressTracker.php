<?php

namespace App\Downloads;

/**
 * Turns yt-dlp's output into an overall percentage.
 *
 * yt-dlp prints our markers (see YtDlp::download()):
 *   PARSER_FORMAT:<format_id>        before the download; "137+140" means two streams (video + audio);
 *   PARSER_PROGRESS:<done>:<total>:<estimate>   for every progress update of the current stream.
 * A new stream starts with "[download] Destination:". Post-processing ([Merger], [ExtractAudio]...)
 * follows the download. Downloading maps to 0–90 %, post-processing to 95 %; 100 % is set only
 * when the task completes. The value never goes backwards.
 */
final class ProgressTracker
{
    /** @var array<string, string> unfinished line per stream (stdout and stderr are read separately) */
    private array $buffers = [];

    private int $streams = 1;

    private int $stream = 0;

    private int $percent = 0;

    /**
     * Feeds a chunk of output; returns the new percentage when it increased, otherwise null.
     */
    public function feed(string $chunk, string $stream = 'out'): ?int
    {
        $buffer = ($this->buffers[$stream] ?? '').$chunk;
        $before = $this->percent;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $this->line(rtrim(substr($buffer, 0, $pos), "\r"));
            $buffer = substr($buffer, $pos + 1);
        }
        $this->buffers[$stream] = $buffer;

        return $this->percent > $before ? $this->percent : null;
    }

    public function percent(): int
    {
        return $this->percent;
    }

    private function line(string $line): void
    {
        if (str_starts_with($line, YtDlp::FORMAT_MARKER)) {
            $this->streams = max(1, substr_count($line, '+') + 1);
        } elseif (str_starts_with($line, '[download] Destination:')) {
            $this->stream = min($this->stream + 1, $this->streams);
        } elseif (str_starts_with($line, YtDlp::PROGRESS_MARKER)) {
            [, $done, $total, $estimate] = array_pad(explode(':', $line), 4, 'NA');
            $size = is_numeric($total) ? (float) $total : (is_numeric($estimate) ? (float) $estimate : 0);
            if ($size > 0 && is_numeric($done)) {
                $current = max(1, $this->stream);
                $fraction = (($current - 1) + min(1, (float) $done / $size)) / $this->streams;
                $this->raise((int) floor($fraction * 90));
            }
        } elseif (preg_match('/^\[(Merger|ExtractAudio|VideoConvertor|VideoRemuxer|FixupM3u8|FixupM4a)\]/', $line)) {
            $this->raise(95);
        }
    }

    private function raise(int $percent): void
    {
        $this->percent = max($this->percent, min(95, $percent));
    }
}
