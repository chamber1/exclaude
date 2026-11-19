<?php

/*
|--------------------------------------------------------------------------
| Downloads (the /parser page)
|--------------------------------------------------------------------------
|
| Every limit here can be overridden from .env, so production can be tuned
| without code changes. Sizes are passed to yt-dlp's --max-filesize and use
| its syntax (500M, 2G). See docs/ARCHITECTURE.md for the whole picture.
|
*/

return [

    'yt_dlp' => [
        'binary' => env('YTDLP_BINARY', '/usr/local/bin/yt-dlp'),
        // Hard limit of one yt-dlp download run (seconds). The queue job timeout and
        // the queue connection's retry_after are derived from it, keep them larger.
        'timeout' => (int) env('DOWNLOADS_PROCESS_TIMEOUT', 600),
        // Metadata lookup (the "Проверить" button) runs inside the HTTP request.
        'inspect_timeout' => (int) env('DOWNLOADS_INSPECT_TIMEOUT', 30),
    ],

    // Queue the download jobs are pushed to (the worker listens to it first).
    'queue' => env('DOWNLOADS_QUEUE', 'downloads'),

    // Private directory on the "local" disk (storage/app/...), one sub-directory per task.
    'directory' => 'downloads',

    // Longest video (seconds) we agree to process.
    'max_duration' => (int) env('DOWNLOADS_MAX_DURATION', 3600),

    // How long a finished file waits to be downloaded (seconds), and the lifetime of its signed link.
    'file_ttl' => (int) env('DOWNLOADS_FILE_TTL', 3600),

    // Metadata of a video is cached this long (seconds), so inspect + create cost one yt-dlp call.
    'info_ttl' => (int) env('DOWNLOADS_INFO_TTL', 600),

    // A pending/processing task without any update for this long (seconds) is failed by the cleanup as stuck.
    // Keep it well above yt_dlp.timeout.
    'stuck_after' => (int) env('DOWNLOADS_STUCK_AFTER', 1800),

    // Single-use links: the file is removed after its first download.
    'delete_after_download' => (bool) env('DOWNLOADS_DELETE_AFTER_DOWNLOAD', true),

    // Internal nginx location that serves storage/app/downloads (X-Accel-Redirect). Empty = PHP sends the file.
    // Requires the matching "internal" location in the nginx config (docker/nginx/*.conf).
    'x_accel_prefix' => env('DOWNLOADS_X_ACCEL_PREFIX', ''),

    // Finished task rows (no files) are deleted after this many days.
    'retention_days' => (int) env('DOWNLOADS_RETENTION_DAYS', 30),

    // Service-wide cap on pending + processing tasks; above it new tasks get "service busy".
    'max_queued' => (int) env('DOWNLOADS_MAX_QUEUED', 100),

    /*
    | Which content may be downloaded:
    |  - "any": everything the platform serves publicly (no DRM, login or paywall bypass in any mode);
    |  - "creative_commons": only videos the uploader published under a Creative Commons licence.
    | The user always has to confirm they have the right to download the content.
    */
    'license_policy' => env('DOWNLOADS_LICENSE_POLICY', 'any'),

    /*
    | Limits per plan. Guests are anonymous visitors; "free" is the default plan of
    | registered users (users.plan); "premium" is prepared for paid plans.
    |  - active: tasks pending or processing at the same time;
    |  - per_minute / per_day: new tasks (Laravel RateLimiter);
    |  - max_height: best video quality allowed (0 = audio only);
    |  - max_filesize: yt-dlp --max-filesize.
    */
    'plans' => [
        'guest' => [
            'active' => (int) env('DOWNLOADS_GUEST_ACTIVE', 1),
            'per_minute' => (int) env('DOWNLOADS_GUEST_PER_MINUTE', 3),
            'per_day' => (int) env('DOWNLOADS_GUEST_PER_DAY', 10),
            'max_height' => (int) env('DOWNLOADS_GUEST_MAX_HEIGHT', 720),
            'max_filesize' => env('DOWNLOADS_GUEST_MAX_FILESIZE', '500M'),
        ],
        'free' => [
            'active' => (int) env('DOWNLOADS_FREE_ACTIVE', 2),
            'per_minute' => (int) env('DOWNLOADS_FREE_PER_MINUTE', 10),
            'per_day' => (int) env('DOWNLOADS_FREE_PER_DAY', 50),
            'max_height' => (int) env('DOWNLOADS_FREE_MAX_HEIGHT', 2160),
            'max_filesize' => env('DOWNLOADS_FREE_MAX_FILESIZE', '2G'),
        ],
        'premium' => [
            'active' => (int) env('DOWNLOADS_PREMIUM_ACTIVE', 5),
            'per_minute' => (int) env('DOWNLOADS_PREMIUM_PER_MINUTE', 30),
            'per_day' => (int) env('DOWNLOADS_PREMIUM_PER_DAY', 500),
            'max_height' => (int) env('DOWNLOADS_PREMIUM_MAX_HEIGHT', 2160),
            'max_filesize' => env('DOWNLOADS_PREMIUM_MAX_FILESIZE', '4G'),
        ],
    ],

    'formats' => ['mp3', 'm4a'],

    // Audio bitrate options (kbit/s). "best" = mp3 VBR V0 / m4a original track without re-encoding.
    'bitrates' => ['best', '320', '256', '192', '128', '96', '64'],

    // Video max height. Up to 1080p YouTube offers H.264, above that only VP9/AV1.
    'qualities' => ['best', '2160', '1440', '1080', '720', '480', '360'],

];
