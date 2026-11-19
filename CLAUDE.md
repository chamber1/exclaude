# CLAUDE.md

Этот файл содержит указания для Claude Code (claude.ai/code) по работе с кодом в этом репозитории.

## Обзор

Приложение на Laravel 13 (PHP 8.5). Основа — заготовка аутентификации `laravel/ui` на Bootstrap (вход, регистрация, сброс пароля, подтверждение email) и Sanctum. Две функции:
- **веб-камера** (`/webcam`, только для вошедших): показывает изображение с камеры, сохраняет снимки и выводит их в галерее;
- **загрузчик YouTube** (`/parser`, доступен и гостям с лимитами): звук (mp3/m4a, выбор битрейта) или видео (mp4, выбор качества). Скачивает через `yt-dlp` воркер очереди, а не HTTP-запрос.

Документация:
- `spec.md` — поведение функций, текущее состояние и бэклог. Обновляйте его при любом изменении функции или её поведения;
- `docs/ARCHITECTURE.md` — загрузки, очередь, лимиты, мониторинг;
- `docs/DEPLOYMENT.md` — запуск, VPS, бэкапы;
- `docs/UPGRADE.md` — обновление PHP и Laravel;
- `AUDIT.md` — аудит и статус исправлений.

## Окружение

Приложение запускается в Docker (`docker-compose.yml`). Все PHP-сервисы собираются из `docker/Dockerfile`, цель `dev`, образ `exclaude-app:dev`:
- `nginx` (цель `web-base`) — отдаёт `public/` на 80 и 443 и передаёт PHP в `app:9000`. Конфиг — `docker/nginx/default.conf`.
  - Для HTTPS используется самоподписанный сертификат в `docker/nginx/certs/` (в `.gitignore`). Если его нет, создайте заново: `docker exec php_fpm sh -c 'cd docker/nginx/certs && openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout localhost.key -out localhost.crt -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"'`.
  - nginx входит в группу gid 33 (`www-data` PHP-образа), чтобы читать готовые загрузки для `X-Accel-Redirect`.
- `app` — PHP 8.5-FPM, только HTTP. **Имя контейнера `php_fpm`**, поэтому `docker exec php_fpm ...` работает, а в `docker compose exec` указывается сервис `app`.
- `queue-worker` — `queue:work redis --queue=downloads,default`, работает от `www-data`.
- `scheduler` — `schedule:work`.
- `mysql` — MySQL 8.0 на `127.0.0.1:3306`, volume `mysql_data`. Тестовую базу `laravel_test` создаёт `docker/mysql/init/` при первой инициализации volume.
- `redis` — Redis 7.4 для кеша, сессий, очереди, rate limiter и локов.

Код подключён bind-mount, изменения применяются без пересборки. **Исключение — воркер:** он держит код в памяти, поэтому после изменения классов, которые использует задача, нужен `docker compose restart queue-worker`.

Настройки PHP лежат в `docker/php_fpm/php.ini` и подключаются как `/etc/php/8.5/{fpm,cli}/conf.d/99-overrides.ini`. Пул FPM настраивается в `docker/php_fpm/zz-pool.conf`. Образ построен на PHP-пакетах Ubuntu (`phpdockerio/php:8.5-fpm`, Ubuntu 24.04, пакеты `php8.5-*`), поэтому `/usr/local/etc/php` не читается.

В dev-образе нет `composer install`: `vendor/` лежит на хосте (`docker exec php_fpm composer install`). Production-образ (цель `prod`) содержит код, `vendor/` и собранные ассеты (`docker-compose.prod.yml`).

Настройки БД и Redis: compose передаёт `DB_HOST=mysql` и `REDIS_HOST=redis`, это перекрывает `127.0.0.1` из `.env`. `DB_DATABASE`, `DB_USERNAME` и `DB_PASSWORD` общие с сервисом mysql. Root-пароль — `DB_ROOT_PASSWORD`. Пользователь MySQL создаётся только при первой инициализации volume, поэтому смена `DB_USERNAME` для существующей базы требует создать пользователя вручную.

`docker exec` выполняется от root, а PHP-FPM и воркер — от `www-data`. Команды artisan, которые пишут в `storage/` или `bootstrap/cache` (`view:cache`, `config:cache`), оставляют там файлы root, и сайт падает с «Permission denied». Запускайте такие команды как `docker exec -u www-data php_fpm ...` (для `tinker` добавьте `-e HOME=/tmp`) либо после них выполните `docker exec php_fpm chown -R www-data:www-data storage bootstrap/cache`.

Хост — Windows:
- В Git Bash нет Node и npm (и Python). Для JS используйте `docker run --rm node:22-alpine` (на этом же образе собираются ассеты в prod-сборке). Текущий `public/build` собран раньше.
- При запуске `docker exec` из Git Bash ставьте перед командой `MSYS_NO_PATHCONV=1`, иначе пути вроде `/var/www/html/...` превращаются в `C:/Program Files/Git/...`.

Изнутри контейнеров nginx доступен по адресу `http://test2-nginx-1`.

Локальный вход для разработки: `test@test.com` / `password`. Пользователь создан вручную в dev-БД, сидера для него нет.

Git: ветка `main`, remote `origin` на GitHub. Папка `.github` есть на диске (CI на PHP 8.4/8.5), но исключена из репозитория через `.gitignore` и `.dockerignore`.

## Команды

```bash
docker compose up -d --build                         # запустить стек (с пересборкой образа)
docker exec php_fpm php artisan migrate              # миграции
docker exec php_fpm php artisan downloads:status     # задачи, очередь, воркеры, диск (--json)
docker exec php_fpm php artisan downloads:cleanup    # очистка вручную (в расписании каждые 5 мин)
docker compose restart queue-worker                  # после изменения кода, который выполняет воркер
docker compose logs -f queue-worker
docker exec php_fpm vendor/bin/pint                  # стиль кода (Pint, пресет Laravel)
docker exec php_fpm vendor/bin/phpunit               # все тесты
docker exec php_fpm vendor/bin/phpunit --filter=test_name
docker exec php_fpm vendor/bin/phpunit tests/Feature/Downloads/DownloadApiTest.php
```

**Тесты** работают с отдельной базой `laravel_test`: в `phpunit.xml` она задана с `force="true"`, а кеш, очередь и сессии — `array` или `sync`. Dev-база не затрагивается.
- В `tests/TestCase.php` перед каждым тестом подключаются `Storage::fake('local')` и подменный DNS (`fakeDns`).
- Есть помощники `fakeYtDlp()` / `videoJson()`: yt-dlp подменяется через `Process::fake()`, к YouTube тесты не обращаются.
- Фейковый процесс не вызывает output-callback, поэтому в коде используйте контракт `Illuminate\Contracts\Process\ProcessResult`, а не конкретный класс.

## Архитектура

- **Структура Laravel 10 сохранена** и работает в Laravel 13: `app/Http/Kernel.php`, `app/Console/Kernel.php` (расписание), `RouteServiceProvider` (rate limiters), полные конфиги в `config/`. Переход на `bootstrap/app.php` — по желанию (`docs/UPGRADE.md`).
- **Маршруты** (`routes/web.php`):
  - `Auth::routes()` (laravel/ui), `/`, `/home`;
  - группа `webcam.*`: middleware `auth` подключается в конструкторе контроллера;
  - `/parser` (только страница);
  - группа `downloads.*` (`DownloadController`): `inspect`, `store`, `index`, `show`, `file` (подписанная ссылка `signed:relative`).
  - `/up` (`HealthController`) — проверка БД, кеша и storage.
- **Виды.** Blade в `resources/views`, страницы наследуют `layouts/app.blade.php`: он подключает `@vite` и `<meta name="csrf-token">`. В layout нет `@stack`, поэтому JS страницы пишется внутри `@section('content')`.
- **Веб-камера**: `WebcamController`, модель `WebcamSnapshot` (`User::webcamSnapshots()`), таблица `webcam_snapshots`.
  - Снимки лежат на **приватном диске `local`** в `storage/app/webcam/{user_id}/` и отдаются только через `webcam.show` с проверкой владельца (`abort_unless`, политик нет), чужим — 404. Не переносите их на диск `public` и не используйте `storage:link`.
  - Клиент — `<script type="module">`: `getUserMedia`, `fetch` с `X-CSRF-TOKEN`. Нужен безопасный контекст: `https://` или `http://localhost`.
  - `<video>` скрыт. Цикл `requestAnimationFrame` рисует кадры на `<canvas>`, а снимок делается через `canvas.toBlob()`, поэтому маска попадает в снимок.
  - Маска клоуна: MediaPipe Face Landmarker импортируется с jsDelivr (версия в `MEDIAPIPE_URL`). `drawClownMask()` рисует её по 468-точечной сетке.
- **Загрузки** (`app/Downloads/*`, подробно — `docs/ARCHITECTURE.md`):
  - **Поток:**
    1. `POST /downloads/inspect` возвращает метаданные (кешируются на 10 мин) и доступные качества;
    2. `POST /downloads` проверяет лимиты под `Cache::lock`, создаёт строку `download_tasks` (`pending`), ставит `DownloadJob` в очередь `downloads` и отвечает `202`;
    3. воркер вызывает `DownloadService::process()`;
    4. страница опрашивает `status_url`;
    5. файл отдаётся по одноразовой подписанной ссылке через `X-Accel-Redirect` на `internal` location `/_protected/downloads/`.
  - **Статусы** (`DownloadStatus`): `pending → processing → completed → expired`, ошибки — `failed`. Задачу забирает атомарный `UPDATE … WHERE status IN (...)`. При повторе после падения воркера частичные файлы удаляются.
  - **Таймауты** вложены: процесс yt-dlp 600 с < `--timeout` воркера 660 с < `retry_after` 900 с. Меняйте их вместе, иначе одну задачу возьмут два воркера.
  - **Безопасность:**
    - `UrlParser` + `Platforms\*` — allowlist хостов, без IP-литералов, userinfo и чужих портов;
    - `NetworkGuard` — DNS только в публичные адреса;
    - в yt-dlp передаётся канонический URL, собранный из ID, а не строка пользователя. Сохраняйте это.
  - **`YtDlp`** — единственное место запуска yt-dlp: массив аргументов, `--ignore-config`, `--use-extractors`, `--no-playlist`, `--no-cache-dir`, `--no-mtime`. Процессу явно передаются `PATH`, `HOME` и `DENO_DIR`: без `PATH` yt-dlp не найдёт ffmpeg и deno.
    - Видео: `-S "res:<h>,vcodec:h264,acodec:aac"` — сначала разрешение. Не ставьте `vcodec` впереди `res`.
    - Для m4a с числовым битрейтом выбирается Opus-дорожка, чтобы шло перекодирование: AAC yt-dlp просто копирует.
    - `--no-quiet` обязателен: `-O`/`--print` включают quiet и скрывают «does not pass filter».
    - С `--print` прогресс уходит в **stderr**, поэтому `ProgressTracker::feed()` читает оба потока с отдельными буферами.
  - **Ошибки:** `YtDlpErrors` переводит вывод yt-dlp в код и русское сообщение, сырой вывод попадает только в лог.
  - **Владелец и лимиты:** `DownloadOwner` — пользователь или гость (HMAC токена сессии). IP хранится только как HMAC (`client_hash`). Лимиты тарифов guest/free/premium — `config/downloads.php` и `.env` (`DOWNLOADS_*`). Тариф — `users.plan`, `users.plan_expires_at`. Режим только CC-лицензий: `DOWNLOADS_LICENSE_POLICY=creative_commons`.
  - **Очистка и мониторинг:** `DownloadCleaner` (TTL файлов, зависшие задачи, сироты, старые строки), `DownloadMonitor` (heartbeat воркера и планировщика, `/up`, `downloads:health app|worker|scheduler` для healthcheck контейнеров).
  - **Лог:** канал `downloads` → `storage/logs/downloads-YYYY-MM-DD.log`, 14 дней, уровень `DOWNLOADS_LOG_LEVEL`. В записях есть uuid задачи, user_id, префикс `client_hash` и id ролика, сырого IP нет.
  - **Инструменты:** yt-dlp и Deno встроены в образ с закреплёнными версиями и SHA-256 (`YTDLP_VERSION`/`DENO_VERSION` в `docker/Dockerfile`), ffmpeg 6.1 ставится из Ubuntu. Обновление — `docs/DEPLOYMENT.md`, затем `docker compose up -d --build`.
- **Версии библиотек.** Carbon 3: `diffIn*()` возвращает float со знаком, приводите к `(int)` и учитывайте направление. `HasUuids` генерирует UUIDv7. Middleware CSRF наследует `PreventRequestForgery`. В PHP 8.4+ нужны явные nullable-типы (`?Type $x = null`).
- **Миграции.** Изменения схемы — только миграциями. Есть и `password_resets` (Laravel 9), и `password_reset_tokens`. `config/auth.php` использует `password_reset_tokens`, так что `password_resets` не используется.
