# Обновление PHP и Laravel

## Итог (19.11.2026)

| Компонент | Было | Стало |
|---|---|---|
| PHP | 8.1.34 (EOL) | 8.5.12 (`phpdockerio/php:8.5-fpm`, Ubuntu 24.04) |
| Laravel | 10.48 (без исправлений безопасности) | 13.43 |
| PHPUnit | 10.5 | 13.4 |
| Sanctum / Tinker | 3 / 2 | 4 / 3 |
| Collision / Ignition / Pint | 7 / 2.x / 1.x | 8.9 / 2.13 / 1.32 |
| Carbon | 2 | 3 |
| ffmpeg | 4.4 | 6.1 |

`composer.json` требует `"php": "^8.4"`. Ниже 8.4 опускаться нельзя: PHPUnit 13 требует PHP ≥ 8.4.1,
Laravel 13 — ≥ 8.3.

## Что изменено

- **Образ:** `docker/Dockerfile`: `FROM phpdockerio/php:8.5-fpm`, пакеты `php8.5-*`, пути `/etc/php/8.5/...`,
  `php-fpm8.5`. `docker-compose.yml`: пути монтирования `php.ini`. CI: матрица PHP 8.4 и 8.5.
- **Зависимости:** `composer.json` и `composer.lock` (`composer update -W`).
- **Carbon 3:** `diffInSeconds()` возвращает float со знаком (раньше int и модуль):
  - `DownloadMonitor::status()`: возраст самой старой pending-задачи теперь `(int) abs(...)`, иначе он был бы отрицательным;
  - `DownloadService` (лог `task completed`): `(int) ... ->diffInSeconds(..., true)`.
- **CSRF:** в Laravel 13 `VerifyCsrfToken` объявлен устаревшим, `App\Http\Middleware\VerifyCsrfToken` теперь
  наследует `PreventRequestForgery`. В `config/sanctum.php` ключ `verify_csrf_token` переименован в `validate_csrf_token` (Sanctum 4).
- **PHP 8.4/8.5:**
  - явные nullable-типы (`?Closure $x = null`): неявные объявлены устаревшими;
  - `PDO::MYSQL_ATTR_SSL_CA` → `Pdo\Mysql::ATTR_SSL_CA` в `config/database.php`.
- **Стиль:** новый пресет Pint применён ко всему коду: `{}` для пустых тел, `new Foo` без скобок, импорты вместо
  полных имён. Логика при этом не менялась.
- **UUID:** `HasUuids` в Laravel 12+ генерирует UUIDv7 (упорядоченные по времени). Колонка и маршруты
  (`whereUuid`) остались прежними, старые v4-идентификаторы продолжают работать.
- **Найдено по ходу:** проверка кеша в `/up` писала все пробы в один ключ, поэтому параллельные проверки
  (nginx, Docker, мониторинг) иногда получали 503. Теперь у каждой пробы свой ключ.

Структура приложения не менялась: `app/Http/Kernel.php`, `app/Console/Kernel.php`, `RouteServiceProvider`,
полные конфиги. Laravel 13 её поддерживает.

## Проверка

- `vendor/bin/phpunit --display-deprecations`: 166 тестов, 492 проверки, без предупреждений об устаревшем коде.
- `vendor/bin/pint --test`: без замечаний.
- **Живой стек:**
  - `/up` отвечает 200, в том числе при 40 параллельных запросах;
  - inspect, без CSRF-токена `419`;
  - задача `202` → `completed`, nginx отдаёт файл (`ffprobe`: H.264 + AAC), повторно `410`, чужой посетитель `404`;
  - `downloads:health` у всех контейнеров.
- **Production-образ:** `prod` и `web` собираются; `config:cache`, `route:cache`, `view:cache` и `php-fpm8.5 -t` проходят.

## Деплой обновления на VPS

Обычная процедура из `docs/DEPLOYMENT.md` («Обновление»). Новых миграций нет. Образ пересобирается
целиком (новая база), поэтому `dc build` займёт больше времени, чем обычно. Откат — предыдущий
`APP_VERSION`: образ со старым PHP остаётся.

## Дальше (по желанию)

1. **Новая структура Laravel 11+:**
   - перенести middleware, расписание (`routes/console.php`) и исключения в `bootstrap/app.php`;
   - удалить Kernel-классы и стандартные middleware-обёртки (`TrustProxies` → `$middleware->trustProxies(at: …)`);
   - сократить конфиги до отличающихся от умолчаний.

   Поведение не меняется, только объём кода.
2. **`password_resets`:** миграцией удалить неиспользуемую таблицу.
3. **Следующие версии:** обновлять `composer update` минорно регулярно, мажорно — по upgrade guide и с тем же
   набором проверок, что выше. Базовый образ — сменой тега `phpdockerio/php:<версия>-fpm` и `php<версия>-*`.
