# План обновления PHP и Laravel

## Почему не обновлено сейчас

| Компонент | Сейчас | Поддержка безопасности |
|---|---|---|
| PHP | 8.1.34 | закончилась 31.12.2025 |
| Laravel | 10.48 | закончилась в феврале 2025 |
| PHPUnit | 10.5 | — |

Обновление меняет сразу три вещи: базовый образ (phpdockerio 8.1 → 8.3/8.4), фреймворк
(10 → 12, через 11) и структуру приложения (`bootstrap/app.php` вместо Kernel-классов). Делать
это вместе с переводом загрузок на очередь значило бы смешать два больших изменения в одном
релизе, и при поломке было бы непонятно, что её вызвало. Новый код загрузок не использует ничего,
что исчезает в Laravel 11/12. Тесты (`vendor/bin/phpunit`, 160+ сценариев) служат страховкой
при обновлении.

## Шаги (отдельной веткой, каждый шаг — зелёные тесты)

1. **PHP 8.3 на Laravel 10.** Laravel 10 поддерживает PHP 8.3.
   - `docker/Dockerfile`: `FROM phpdockerio/php:8.3-fpm`, пакеты `php8.3-*`, пути
     `/etc/php/8.3/...`, `CMD ["php-fpm8.3", "-F"]`.
   - `docker-compose.yml`: пути монтирования php.ini.
   - `composer.json`: `"php": "^8.2"`; затем `composer update`.
   - Прогнать тесты и сквозную проверку загрузки (ниже).
2. **Laravel 11.**
   - `composer.json`: `laravel/framework:^11.0`, `nunomaduro/collision:^8.1`,
     `phpunit/phpunit:^11`, `laravel/ui:^4.5`; удалить `fruitcake`/`doctrine/dbal`, если есть.
   - Структуру приложения можно не менять: Laravel 11 поддерживает старую структуру
     с `app/Http/Kernel.php` и `app/Console/Kernel.php`.
   - Проверить [upgrade guide 11.x](https://laravel.com/docs/11.x/upgrade). Для этого проекта
     важно:
     - каскадные изменения колонок в миграциях: при `->change()` нужно перечислять все атрибуты;
     - `Model::casts()` как метод (свойство `$casts` продолжает работать);
     - в `Limit` / `ThrottlesExceptions` интервал задаётся в секундах (`decaySeconds`), проверить
       `RouteServiceProvider::configureRateLimiting()`;
     - `doctrine/dbal` больше не нужен.
3. **Laravel 12.** Изменений для этого кода практически нет (см. upgrade guide 12.x).
4. **По желанию: новая структура.**
   - Перенести middleware, расписание (`routes/console.php`) и исключения в `bootstrap/app.php`.
   - Удалить Kernel-классы и стандартные middleware-обёртки (`TrustProxies` →
     `$middleware->trustProxies(at: …)`).
5. **PHP 8.4.** После того как все зависимости объявят поддержку. Pint нужно настроить на явные
   nullable-типы (`?Type $x = null`): неявные в 8.4 объявлены устаревшими.

## Проверка каждого шага

```bash
docker compose build app && docker compose up -d
docker compose exec app vendor/bin/phpunit
docker compose exec app php artisan downloads:status
```

Ручная проверка:
- открыть `/parser`, проверить ссылку;
- скачать звук и видео 360p, посмотреть прогресс и получение файла;
- `/webcam` после входа;
- `docker compose logs queue-worker` без ошибок.
