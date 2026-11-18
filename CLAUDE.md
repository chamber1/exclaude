# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Laravel 10 (PHP 8.1) app built on the `laravel/ui` Bootstrap auth scaffolding (login, register, password reset, email verification) plus Sanctum. There are two features, both behind auth:
- a **webcam page** (`/webcam`): it shows the user's live camera, saves snapshots and lists them in a gallery;
- a **YouTube audio parser** (`/parser`): it downloads a video's audio track as mp3/m4a using `yt-dlp` inside php_fpm. See `spec.md` for the features' behaviour, the current project state and the open backlog. Update `spec.md` whenever a feature or its behaviour changes.

## Environment

The app runs in Docker (`docker-compose.yml`):
- `nginx` serves `public/` on ports 80 and 443 and forwards PHP to `php_fpm:9000`. HTTPS uses a self-signed certificate in `docker/nginx/certs/` (gitignored). If it's missing, regenerate it with `docker exec php_fpm sh -c 'cd docker/nginx/certs && openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout localhost.key -out localhost.crt -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"'`.
- `php_fpm` (container name `php_fpm`, built from `docker/Dockerfile`) runs PHP 8.1-FPM.
- `mysql` runs MySQL 8.0 on port 3306, with data in the `mysql_data` volume.

The project directory is bind-mounted into both nginx and php_fpm, so code changes take effect without a rebuild. PHP settings live in `docker/php_fpm/php.ini`, mounted as `/etc/php/8.1/{fpm,cli}/conf.d/99-overrides.ini`. The image uses Ubuntu's PHP packages, so `/usr/local/etc/php` is not read. The Dockerfile does **not** run `composer install`, so `vendor/` must exist on the host.

Database settings: compose injects `DB_HOST=mysql` and the user `user` into php_fpm, which overrides `DB_HOST=127.0.0.1` from `.env`. `DB_DATABASE` and `DB_PASSWORD` come from `.env` and are shared with the mysql service. Run artisan commands inside the container. Run them on the host only if a local MySQL is available.

`docker exec` runs as root, while PHP-FPM runs as `www-data`. Artisan commands that write into `storage/` or `bootstrap/cache` (e.g. `view:cache`, `config:cache`) leave root-owned files there, and the site then fails with "Permission denied". Run such commands with `docker exec -u www-data php_fpm ...`, or afterwards run `docker exec php_fpm chown -R www-data:www-data storage bootstrap/cache`.

The host is Windows and has neither Node nor npm installed, so `npm run dev/build` won't work there as-is (the current `public/build` was built earlier). For one-off JS checks use `docker run --rm node:20-alpine`. When running `docker exec` from Git Bash, prefix it with `MSYS_NO_PATHCONV=1`, otherwise container paths like `/var/www/html/...` get rewritten to `C:/Program Files/Git/...`. From inside php_fpm, nginx is reachable at `http://test2-nginx-1`.

Local dev login: `test@test.com` / `password` (created manually in the dev DB, not seeded).

The project is not a git repository.

## Commands

```bash
docker compose up -d --build                         # start stack
docker exec php_fpm php artisan migrate              # run migrations
docker exec php_fpm php artisan <command>            # any artisan command
npm run dev                                          # Vite dev server (resources/sass/app.scss, resources/js/app.js)
npm run build                                        # production asset build (public/build)
docker exec php_fpm vendor/bin/pint                  # code style (Laravel Pint)
docker exec php_fpm vendor/bin/phpunit               # all tests
docker exec php_fpm vendor/bin/phpunit --filter=test_name       # single test
docker exec php_fpm vendor/bin/phpunit tests/Feature/ExampleTest.php
```

**Testing caveat:** `phpunit.xml` has the SQLite in-memory DB lines commented out, so tests use the real MySQL database from `.env`. Any test using `RefreshDatabase` will wipe the dev database, including the test user and snapshots. Uncomment the sqlite settings in `phpunit.xml` or use a separate test database first. Only the example tests exist so far.

## Architecture notes

- Routes: `routes/web.php` registers `Auth::routes()` (the `laravel/ui` macro, which maps to the controllers in `app/Http/Controllers/Auth/`), `/` (welcome), `/home`, and the `webcam.*` group. Controllers apply the `auth` middleware in their constructors, not in route groups.
- Views: Blade templates in `resources/views`. Pages extend `layouts/app.blade.php`, which loads assets via `@vite` and exposes the CSRF token as `<meta name="csrf-token">`. The layout has no `@stack`, so page JS goes inline inside `@section('content')` (as in `webcam.blade.php`).
- Webcam feature: `WebcamController`, `WebcamSnapshot` model (`User::webcamSnapshots()` hasMany), and the `webcam_snapshots` table.
  - Snapshot files go on the **private `local` disk** under `storage/app/webcam/{user_id}/`. They are served only through `webcam.show`, which checks ownership and returns 404 for other users. Don't move them to the `public` disk or rely on `storage:link`.
  - Ownership is checked inline with `abort_unless(...user_id === ...)`; there are no policies.
  - The browser side is a vanilla `<script type="module">` in the view (`getUserMedia`, `fetch` with `X-CSRF-TOKEN`). `getUserMedia` needs a secure context, so the page only works over `https://` or `http://localhost`.
  - The `<video>` is a hidden source. A `requestAnimationFrame` loop paints each frame onto the visible `<canvas>`, and snapshots are `canvas.toBlob()`, so whatever is drawn (e.g. the clown mask) ends up in the saved image.
  - Clown mask: MediaPipe Face Landmarker is dynamically imported from jsDelivr (version pinned in `MEDIAPIPE_URL`) the first time the toggle is switched on. `drawClownMask()` draws from 468-point face-mesh indices, oriented along the chin→forehead axis.
- Parser feature (`ParserController`, `parser.blade.php`, no model or table):
  - `download()` runs `yt-dlp` **synchronously** via the `Process` facade and returns the file with `deleteFileAfterSend()`. The page JS fetches it as a blob.
  - The user's URL is reduced to an 11-character video ID by `extractVideoId()`, and only `https://www.youtube.com/watch?v=<id>` (after `--`) reaches the command line. Keep it that way.
  - php-fpm runs with a cleared environment, so the process gets `PATH`, `HOME` and `DENO_DIR` explicitly. Without `PATH`, yt-dlp can't find ffmpeg or deno.
  - Timeouts are stacked: yt-dlp 600 s, then `set_time_limit` 660 s, then nginx `fastcgi_read_timeout 660s`. Change them together.
  - yt-dlp errors are mapped to Russian messages by substring in `errorMessage()`. Unmapped failures are logged as `yt-dlp failed`.
  - `yt-dlp`, `ffmpeg` and `deno` are baked into the php_fpm image (`docker/Dockerfile`). Changing them needs `docker compose up -d --build`. yt-dlp is unpinned on purpose; update it in place with `docker exec php_fpm yt-dlp -U`.
- Migrations: both `password_resets` (Laravel 9) and `password_reset_tokens` (Laravel 10) exist. `config/auth.php` uses `password_reset_tokens`, so `password_resets` is unused.
- `.github/workflows` are inherited from the upstream Laravel skeleton. `tests.yml` runs `vendor/bin/phpunit` on PHP 8.1 and 8.2.
