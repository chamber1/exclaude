# Архитектура загрузок

Раньше `POST /parser` запускал yt-dlp прямо в запросе PHP-FPM: пять одновременных загрузок
занимали все пять воркеров FPM, и сайт переставал отвечать. Теперь запрос только создаёт задачу.
Скачивает отдельный воркер очереди, а страница опрашивает статус.

## Поток

```
Браузер                 nginx         app (PHP-FPM)                   Redis           queue-worker
   │ POST /downloads/inspect ─────────► UrlParser → NetworkGuard
   │                                    → yt-dlp --dump-single-json (кеш 10 мин)
   │ ◄──────────── метаданные, доступные качества, лимиты тарифа
   │ POST /downloads ─────────────────► лимиты под Cache::lock
   │                                    → INSERT download_tasks (pending)
   │                                    → DownloadJob ───────────────► очередь downloads
   │ ◄──────────── 202 {id, status_url}                                      │
   │                                                                         ▼
   │                                                          DownloadService::process()
   │                                                          pending→processing (атомарно)
   │                                                          yt-dlp → storage/app/downloads/<uuid>/
   │                                                          progress в БД раз в 2 с
   │                                                          → completed (+expires_at)
   │ GET /downloads/{uuid}, каждые 1,5 с ──► статус, прогресс, подписанная ссылка
   │ GET /downloads/{uuid}/file?expires&signature
   │                          ──────────► подпись + владелец, completed→expired (одноразово)
   │ ◄── файл ◄── X-Accel-Redirect: /_protected/downloads/<uuid>/media.mp4
```

Файл отдаёт nginx (`internal` location), а не PHP. Поэтому скачивание на несколько минут не держит
воркер FPM, и работают Range-запросы. Если `DOWNLOADS_X_ACCEL_PREFIX` пустой, файл отдаёт PHP
(`BinaryFileResponse`), это fallback для окружения без nginx.

## Компоненты (`app/Downloads`)

| Класс | Ответственность |
|---|---|
| `UrlParser`, `Platforms\YouTube` | Ссылка → `MediaReference(platform, id)`. Allowlist хостов, только http(s), без логина/пароля и нестандартных портов, без IP-литералов. Дальше передаётся канонический URL, собранный из id, а не строка пользователя. |
| `NetworkGuard` | Второй барьер SSRF: хост резолвится только в публичные адреса (RFC 6890, CGNAT, IPv4 внутри IPv6). |
| `YtDlp` | Единственное место, где запускается yt-dlp. Массив аргументов (без shell), `--ignore-config`, `--use-extractors youtube` (generic-экстрактор выключен), `--no-playlist`, таймаут, `--max-filesize` тарифа, match-filter (длительность, не трансляция, при необходимости лицензия CC). |
| `ProgressTracker` | Разбирает маркеры yt-dlp в общий процент: 0–90 % загрузка (видео и аудио поровну), 95 % постобработка. |
| `YtDlpErrors` | Вывод yt-dlp → код и понятное сообщение. Сырой вывод попадает только в лог. |
| `DownloadService` | Бизнес-правила: inspect, create (лимиты), process, fail, expire, deliver, подписанные ссылки. |
| `DownloadOwner` | Кто владеет задачей и какой тариф. Гость = HMAC случайного токена в сессии. IP хранится только как HMAC (`client_hash`) и нужен для лимитов гостя. |
| `DownloadCleaner` | Истёкшие файлы, зависшие задачи, осиротевшие каталоги, старые строки. |
| `DownloadMonitor` | Heartbeat воркеров и планировщика, проверки здоровья, сводка для `downloads:status`. |
| `Jobs\DownloadJob` | Тонкая обёртка над `DownloadService::process()`. `tries=2`, `timeout=660`, `ShouldBeUnique`. |

## Статусы задачи

```
pending ──► processing ──► completed ──► expired   (скачан или истёк срок)
   │             │
   └─────────────┴──────► failed       (ошибка yt-dlp, таймаут, зависла, job failed)
```

- **Захват атомарный:** `UPDATE … WHERE id=? AND status IN (pending, processing)`. Повторный запуск
  завершённой задачи ничего не делает.
- **Падение воркера** (OOM, `docker kill`): задача остаётся `processing`. Redis возвращает job
  в очередь через `retry_after` (900 с), следующая попытка удаляет частичные файлы и начинает
  заново (`attempts + 1`). После `tries` вызывается `DownloadJob::failed()`, и задача становится
  `failed`. Если job потерян совсем, `downloads:cleanup` переводит задачу в `failed`, когда та
  `stuck_after` (30 мин) не обновлялась.
- **Ожидаемые ошибки** (приватное видео, слишком длинное, DRM, 429 от платформы) сразу ставят
  `failed` без повторов: повтор не поможет.

## Лимиты

| | Гость | Free | Premium |
|---|---|---|---|
| Активных задач | 1 | 2 | 5 |
| Задач в минуту | 3 | 10 | 30 |
| Задач в сутки | 10 | 50 | 500 |
| Макс. качество | 720p | 2160p | 2160p |
| Макс. размер файла | 500 МБ | 2 ГБ | 4 ГБ |

Все значения переопределяются через `.env` (`DOWNLOADS_<PLAN>_<LIMIT>`, см. `config/downloads.php`).
- **Активные и суточные лимиты** проверяются под `Cache::lock` на владельца, поэтому параллельные
  запросы не превысят лимит.
- **Гостевые лимиты** считаются и по сессии, и по HMAC IP: новая сессия их не сбрасывает.
- **Глобальный лимит** `DOWNLOADS_MAX_QUEUED` (100 задач в очереди) защищает от переполнения.
  Сверх него ответ 503 «сервис занят».
- **Ограничители частоты** (`RouteServiceProvider`): `downloads` (создание), `downloads-inspect`
  (10 или 30 в минуту), `downloads-status` (120 в минуту).
- **Тариф** хранится в `users.plan` и `users.plan_expires_at`. Истёкший premium считается free.

## Очередь и Redis

- **Хранилище:** Redis служит и очередью, и кешем, и сессиями, и rate limiter, и локами.
  Включены `appendonly yes` и `maxmemory-policy noeviction`: при нехватке памяти Redis отвечает
  ошибкой, но не выбрасывает задачи очереди и сессии.
- **Очереди:** `downloads` для загрузок и `default` для остального. Воркер:
  `queue:work redis --queue=downloads,default --tries=2 --timeout=660 --max-jobs=100 --max-time=3600`.
  Он перезапускается сам (`restart: unless-stopped`), так что утечки памяти не копятся.
- **Таймауты:** `retry_after` (900) должен быть больше `--timeout` (660), а тот больше таймаута
  процесса yt-dlp (600). Иначе одну задачу возьмут два воркера.
- **Масштабирование:** одна загрузка занимает один воркер. Параллельность задаётся числом
  контейнеров: `QUEUE_WORKERS=4` в `.env` или `docker compose -f docker-compose.prod.yml up -d --scale queue-worker=4`.
  Каждому нужно около 1 CPU и до 1,5 ГБ RAM (ffmpeg при склейке 4K).

## Мониторинг

- **`GET /up`:** БД, кеш, запись в storage. Отвечает 200 или 503 и используется healthcheck nginx.
- **`php artisan downloads:health app|worker|scheduler`:** healthcheck контейнеров (exit code).
  Воркер считается живым по heartbeat раз в цикл (`Queue::looping`). Во время долгой загрузки
  heartbeat продлевается на время задачи.
- **`php artisan downloads:status [--json]`:** задачи по статусам, возраст самой старой pending,
  длина очереди, failed jobs, воркеры, свободное место.
- **Планировщик** (`app/Console/Kernel.php`):

  | Задача | Частота |
  |---|---|
  | `downloads:cleanup` | каждые 5 мин |
  | `queue:monitor redis:downloads --max=50` | каждые 5 мин, warning в лог при длинной очереди |
  | `queue:prune-failed --hours=168` | раз в сутки |
  | heartbeat | каждую минуту |

- **Логи:** `storage/logs/downloads-YYYY-MM-DD.log` (14 дней). В записях есть uuid задачи, user_id,
  префикс HMAC клиента, id ролика и тип. Сырой IP не пишется. Вывод yt-dlp при ошибке тоже идёт
  сюда (до 8 КБ, без строк прогресса).

## Хранение данных

- **Файлы** лежат в `storage/app/downloads/<uuid>/media.<ext>`: каталог 0750, владелец www-data.
  nginx читает их через группу (gid 33), записи у него нет. Файл удаляется после скачивания
  (одноразовая ссылка) или через `DOWNLOADS_FILE_TTL` (1 ч).
- **Строки `download_tasks`** со статусами failed и expired удаляются через `DOWNLOADS_RETENTION_DAYS` (30).
- **Персональные данные:** user_id (для зарегистрированных), HMAC токена сессии, HMAC IP. Ключ
  HMAC равен `APP_KEY`: при его смене старые хеши перестают совпадать, а гостевые лимиты сбрасываются.

## Политика контента

- **Обход ограничений:** сервис не обходит DRM, авторизацию, платный и members-only контент.
  Такие ролики завершаются ошибкой с понятным сообщением (`YtDlpErrors`). Cookies и аккаунты
  платформы не используются.
- **Режим лицензий:** `DOWNLOADS_LICENSE_POLICY=creative_commons` разрешает только ролики,
  опубликованные под Creative Commons. Это проверяется и на этапе inspect, и в match-filter yt-dlp.

## Добавление платформы

1. Реализовать `App\Downloads\Platforms\Platform`: имя, точный список хостов, экстрактор yt-dlp,
   извлечение id, канонический URL.
2. Добавить класс в `UrlParser::__construct()`.
3. Добавить тесты в `tests/Unit/Downloads/UrlParserTest.php`.
4. Проверить, что правила платформы разрешают скачивание.
