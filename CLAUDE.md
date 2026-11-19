# CLAUDE.md

Этот файл содержит указания для Claude Code (claude.ai/code) по работе с кодом в этом репозитории.

## Обзор

Приложение на Laravel 10 (PHP 8.1). Основа — заготовка аутентификации `laravel/ui` на Bootstrap (вход, регистрация, сброс пароля, подтверждение email) и Sanctum. В приложении две функции, обе только для вошедших пользователей:
- **страница веб-камеры** (`/webcam`): показывает изображение с камеры пользователя, сохраняет снимки и выводит их в галерее;
- **парсер YouTube** (`/parser`): скачивает звук ролика (mp3/m4a, с выбором битрейта) или видео (mp4, с выбором качества) через `yt-dlp` внутри php_fpm.

Поведение функций, текущее состояние проекта и открытые задачи описаны в `spec.md`. Обновляйте `spec.md` при любом изменении функции или её поведения.

## Окружение

Приложение запускается в Docker (`docker-compose.yml`):
- `nginx` отдаёт `public/` на портах 80 и 443 и передаёт PHP-запросы в `php_fpm:9000`. Для HTTPS используется самоподписанный сертификат в `docker/nginx/certs/` (в `.gitignore`). Если его нет, создайте заново: `docker exec php_fpm sh -c 'cd docker/nginx/certs && openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout localhost.key -out localhost.crt -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"'`.
- `php_fpm` (имя контейнера `php_fpm`, собирается из `docker/Dockerfile`) — PHP 8.1-FPM.
- `mysql` — MySQL 8.0 на порту 3306, данные в volume `mysql_data`.

Каталог проекта подключён в nginx и php_fpm как bind-mount, поэтому изменения кода применяются без пересборки.

Настройки PHP лежат в `docker/php_fpm/php.ini` и подключаются как `/etc/php/8.1/{fpm,cli}/conf.d/99-overrides.ini`. Образ построен на PHP-пакетах Ubuntu, поэтому `/usr/local/etc/php` не читается.

Dockerfile **не** выполняет `composer install`, так что `vendor/` должен быть на хосте.

Настройки БД: compose передаёт в php_fpm `DB_HOST=mysql`, и это перекрывает `DB_HOST=127.0.0.1` из `.env`. `DB_DATABASE`, `DB_USERNAME` и `DB_PASSWORD` берутся из `.env` и общие с сервисом mysql (`MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`). Пользователь MySQL создаётся только при первой инициализации volume `mysql_data`, поэтому смена `DB_USERNAME` в `.env` для существующей базы требует создать пользователя вручную. Команды artisan запускайте внутри контейнера, на хосте — только если там есть свой MySQL.

`docker exec` выполняется от root, а PHP-FPM работает от `www-data`. Команды artisan, которые пишут в `storage/` или `bootstrap/cache` (например `view:cache`, `config:cache`), оставляют там файлы root, и сайт падает с «Permission denied». Запускайте такие команды как `docker exec -u www-data php_fpm ...` (для `tinker` добавьте `-e HOME=/tmp`, иначе psysh не сможет создать `/var/www/.config`) либо после них выполните `docker exec php_fpm chown -R www-data:www-data storage bootstrap/cache`.

Хост — Windows:
- Node и npm не установлены, поэтому `npm run dev/build` там сразу не заработают (текущий `public/build` собран раньше). Для разовых проверок JS используйте `docker run --rm node:20-alpine`.
- При запуске `docker exec` из Git Bash ставьте перед командой `MSYS_NO_PATHCONV=1`, иначе пути внутри контейнера вроде `/var/www/html/...` превращаются в `C:/Program Files/Git/...`.

Изнутри php_fpm nginx доступен по адресу `http://test2-nginx-1`.

Локальный вход для разработки: `test@test.com` / `password`. Пользователь создан вручную в dev-БД, сидера для него нет.

Git: ветка `main`, remote `origin` на GitHub. Папка `.github` (CI-workflows из шаблона Laravel) есть на диске, но исключена из репозитория через `.gitignore` и `.dockerignore`.

## Команды

```bash
docker compose up -d --build                         # запустить стек (с пересборкой образа)
docker exec php_fpm php artisan migrate              # выполнить миграции
docker exec php_fpm php artisan <command>            # любая команда artisan
npm run dev                                          # dev-сервер Vite (resources/sass/app.scss, resources/js/app.js)
npm run build                                        # сборка ассетов для продакшена (public/build)
docker exec php_fpm vendor/bin/pint                  # стиль кода (Laravel Pint)
docker exec php_fpm vendor/bin/phpunit               # все тесты
docker exec php_fpm vendor/bin/phpunit --filter=test_name       # один тест
docker exec php_fpm vendor/bin/phpunit tests/Feature/ExampleTest.php
```

**Осторожно с тестами:** в `phpunit.xml` закомментированы строки с SQLite in-memory, поэтому тесты работают с настоящей MySQL-базой из `.env`. Любой тест с `RefreshDatabase` сотрёт dev-базу вместе с тестовым пользователем и снимками. Сначала раскомментируйте настройки SQLite в `phpunit.xml` или заведите отдельную тестовую БД. Пока есть только примеры тестов.

## Архитектура

- **Маршруты.** `routes/web.php` регистрирует:
  - `Auth::routes()` — макрос `laravel/ui`, ведёт на контроллеры в `app/Http/Controllers/Auth/`;
  - `/` (welcome) и `/home`;
  - группы `webcam.*` и `parser.*`.

  Middleware `auth` подключается в конструкторах контроллеров, а не в группах маршрутов.
- **Виды.** Blade-шаблоны в `resources/views`. Страницы наследуют `layouts/app.blade.php`: он подключает ассеты через `@vite` и выводит CSRF-токен как `<meta name="csrf-token">`. В layout нет `@stack`, поэтому JS страницы пишется прямо внутри `@section('content')` (как в `webcam.blade.php` и `parser.blade.php`).
- **Веб-камера**: `WebcamController`, модель `WebcamSnapshot` (`User::webcamSnapshots()` — hasMany), таблица `webcam_snapshots`.
  - Файлы снимков лежат на **приватном диске `local`** в `storage/app/webcam/{user_id}/`. Отдаются только через `webcam.show`, который проверяет владельца и возвращает 404 чужим пользователям. Не переносите их на диск `public` и не используйте `storage:link`.
  - Владелец проверяется прямо в коде через `abort_unless(...user_id === ...)`, политик нет.
  - Клиентская часть — обычный `<script type="module">` в виде (`getUserMedia`, `fetch` с `X-CSRF-TOKEN`). `getUserMedia` требует безопасного контекста, поэтому страница работает только по `https://` или `http://localhost`.
  - `<video>` — скрытый источник. Цикл `requestAnimationFrame` рисует каждый кадр на видимом `<canvas>`, а снимок делается через `canvas.toBlob()`. Поэтому всё нарисованное (например маска клоуна) попадает в сохранённое изображение.
  - Маска клоуна: MediaPipe Face Landmarker динамически импортируется с jsDelivr (версия зафиксирована в `MEDIAPIPE_URL`) при первом включении переключателя. `drawClownMask()` рисует маску по индексам 468-точечной сетки лица и поворачивает её по оси подбородок→лоб.
- **Парсер** (`ParserController`, `parser.blade.php`, без модели и таблицы):
  - `download()` запускает `yt-dlp` **синхронно** (общий метод `runYtDlp()`, через фасад `Process`).
    - Звук сразу отдаётся с `deleteFileAfterSend()`, и JS получает его как blob.
    - Видео может весить гигабайты, поэтому в blob его не берут. `download()` возвращает JSON со ссылкой на `parser.file`; данные ссылки лежат в кеше `parser:file:<uuid>` (владелец, путь, имя, TTL 1 час). `file()` отдаёт файл один раз и только владельцу.
  - Видео: `-S "res:<h>,vcodec:h264,acodec:aac"` — сначала разрешение, затем H.264/AAC. Не ставьте `vcodec` впереди `res`: тогда H.264 начнёт перевешивать разрешение.
  - Ссылка пользователя сводится к 11-символьному ID ролика в `extractVideoId()`. В командную строку (после `--`) попадает только `https://www.youtube.com/watch?v=<id>`. Это нужно сохранить.
  - Битрейт (`best`/`320`…`64`) передаётся как `--audio-quality` (`0` или `192K` → `-b:a 192k`). Из AAC-источника в m4a yt-dlp просто копирует дорожку и игнорирует битрейт, поэтому `formatSelector()` для m4a с числовым битрейтом выбирает Opus-дорожку, чтобы шло перекодирование.
  - Всегда передавайте `--no-mtime`. Без него yt-dlp ставит файлу дату загрузки ролика, и `removeStaleFiles()` (удаляет файлы старше часа) может удалить его во время соседнего запроса.
  - php-fpm запускается с очищенным окружением, поэтому процессу явно передаются `PATH`, `HOME` и `DENO_DIR`. Без `PATH` yt-dlp не найдёт ffmpeg и deno.
  - Таймауты вложены друг в друга: yt-dlp 600 с, затем `set_time_limit` 660 с, затем nginx `fastcgi_read_timeout 660s`. Меняйте их вместе.
  - Ошибки yt-dlp переводятся в русские сообщения поиском подстроки в `errorMessage()`. Пользователь видит только это сообщение, без отсылок к логу.
  - `-O` (заголовок ролика) включает у yt-dlp режим `--quiet` и скрывает сообщения вроде «does not pass filter». Поэтому команда запускается с `--no-quiet`, а заголовок ищется в stdout по префиксу `TITLE_MARKER`. Не убирайте `--no-quiet`: без него ошибки «длиннее 60 минут» превращаются в общую.
  - Лог: отдельный канал `parser` (`config/logging.php`) → `storage/logs/parser-YYYY-MM-DD.log`, хранится 14 дней, уровень задаётся `PARSER_LOG_LEVEL`. Пишется через `$this->log()`. Все записи одного запроса связаны полем `rid` и содержат `user_id`, `ip`, `video`. Что пишется:
    - `request` — параметры запроса;
    - `validation failed` / `not a YouTube video URL`;
    - `yt-dlp start` (debug, полная команда);
    - `yt-dlp done` (время, заголовок, размер, WARNING-строки);
    - `yt-dlp failed` (код выхода, причина, сообщение пользователю, stdout/stderr до 8000 символов);
    - `yt-dlp timed out`;
    - `audio sent` / `video prepared` / `video sent`;
    - `file download denied: <причина>` (уровень warning, если токен принадлежит другому пользователю).
  - `yt-dlp`, `ffmpeg` и `deno` встроены в образ php_fpm (`docker/Dockerfile`). Изменение требует `docker compose up -d --build`. Версия yt-dlp намеренно не зафиксирована; обновить его на месте можно командой `docker exec php_fpm yt-dlp -U`.
- **Миграции.** Есть и `password_resets` (Laravel 9), и `password_reset_tokens` (Laravel 10). `config/auth.php` использует `password_reset_tokens`, так что `password_resets` не используется.
