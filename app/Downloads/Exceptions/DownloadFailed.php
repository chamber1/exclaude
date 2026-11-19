<?php

namespace App\Downloads\Exceptions;

/**
 * yt-dlp could not produce the file for a reason we can explain to the user
 * (private video, too long, too large...). The task is failed and the job is not retried.
 */
class DownloadFailed extends DownloadRejected
{
}
