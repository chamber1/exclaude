<?php

namespace Tests;

use App\Downloads\NetworkGuard;
use App\Downloads\YtDlp;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Downloads go to a throw-away disk; tests never touch the real storage/app/downloads.
        Storage::fake('local');
        // No real DNS lookups: the platform host resolves to a public address.
        $this->fakeDns(['142.250.74.78']);
    }

    /** @param  list<string>  $ips  what every host resolves to */
    protected function fakeDns(array $ips): void
    {
        $this->app->instance(NetworkGuard::class, new NetworkGuard(fn (string $host) => $ips));
    }

    /** yt-dlp JSON metadata as returned by --dump-single-json. */
    protected function videoJson(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'jNQXAC9IVRw',
            'title' => 'Me at the zoo',
            'duration' => 19,
            'uploader' => 'jawed',
            'thumbnail' => 'https://i.ytimg.com/vi/jNQXAC9IVRw/maxresdefault.jpg',
            'license' => null,
            'is_live' => false,
            'formats' => [
                ['format_id' => '140', 'vcodec' => 'none', 'height' => null],
                ['format_id' => '134', 'vcodec' => 'avc1', 'height' => 360],
                ['format_id' => '136', 'vcodec' => 'avc1', 'height' => 720],
                ['format_id' => '137', 'vcodec' => 'avc1', 'height' => 1080],
            ],
        ];
    }

    /**
     * Fakes yt-dlp: metadata requests return $json; a download creates a file from the "-o"
     * template and prints the markers YtDlp reads, unless $download handles it.
     *
     * @param  (Closure(list<string>): mixed)|null  $download  receives the command, returns a process result
     */
    protected function fakeYtDlp(?array $json = null, ?Closure $download = null): void
    {
        $json ??= $this->videoJson();

        Process::fake(function (PendingProcess $process) use ($json, $download) {
            $command = (array) $process->command;

            if (in_array('--dump-single-json', $command, true)) {
                return Process::result(json_encode($json));
            }
            if ($download !== null) {
                return $download($command);
            }

            $template = $command[array_search('-o', $command, true) + 1];
            $ext = in_array('--merge-output-format', $command, true)
                ? 'mp4'
                : $command[array_search('--audio-format', $command, true) + 1];
            $file = str_replace('%(ext)s', $ext, $template);
            file_put_contents($file, 'fake media');

            return Process::result(implode("\n", [
                YtDlp::FORMAT_MARKER.'136+140',
                YtDlp::FILE_MARKER.$file,
                YtDlp::TITLE_MARKER.$json['title'],
            ])."\n");
        });
    }
}
