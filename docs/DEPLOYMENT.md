# Запуск и эксплуатация

Ниже `dc` — сокращение для production-команды:

```bash
alias dc='docker compose -f docker-compose.prod.yml'
```

## 1. Локально (разработка)

```bash
cp .env.example .env            # заполнить DB_PASSWORD (и при желании DB_ROOT_PASSWORD)
docker compose up -d --build    # nginx, app, queue-worker, scheduler, mysql, redis
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
npm ci && npm run build             # на хосте (Node 20+): собирает public/build
```

- Сайт: http://localhost/parser.
- Код смонтирован в контейнеры. После изменения PHP-классов, которые использует воркер,
  перезапустите его: `docker compose restart queue-worker` (воркер держит код в памяти).
- Тесты используют отдельную базу `laravel_test` (её создаёт `docker/mysql/init/01-test-database.sh`
  при первом создании тома MySQL) и не трогают рабочую базу:

```bash
docker compose exec app vendor/bin/phpunit
docker compose exec app vendor/bin/phpunit --filter DownloadApiTest
```

Если том MySQL создан раньше и `laravel_test` нет:

```bash
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS laravel_test; GRANT ALL ON laravel_test.* TO \"$MYSQL_USER\"@\"%\";"'
```

Состояние: `docker compose ps` (у всех `healthy`), `docker compose exec app php artisan downloads:status`.

## 2. VPS: первая установка

Требования: Ubuntu 22.04/24.04, 2+ vCPU, 4+ ГБ RAM, 40+ ГБ диска, домен с A-записью на сервер.

```bash
# Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER && newgrp docker

# Межсетевой экран: только SSH и веб
sudo ufw allow OpenSSH && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw enable

# Код
sudo mkdir -p /opt/exclaude && sudo chown $USER /opt/exclaude
git clone https://github.com/chamber1/exclaude.git /opt/exclaude
cd /opt/exclaude

# Настройки
cp .env.production.example .env && chmod 600 .env
nano .env    # APP_DOMAIN, APP_URL, DB_PASSWORD, DB_ROOT_PASSWORD, REDIS_PASSWORD, MAIL_*
```

Пароли: `openssl rand -base64 32 | tr -d '/+='`. APP_KEY:

```bash
dc build
dc run --rm --no-deps app php artisan key:generate --show    # вставить в .env как APP_KEY=
```

> Docker публикует порты в обход ufw. В `docker-compose.prod.yml` наружу открыты только 80 и 443
> (nginx). У MySQL и Redis портов нет, они доступны только внутри сети compose.

### SSL (Let's Encrypt)

nginx не стартует без сертификата, поэтому первый сертификат получаем до запуска nginx,
в standalone-режиме certbot (порт 80 должен быть свободен):

```bash
source <(grep -E '^APP_DOMAIN=' .env)
mkdir -p docker/production/certbot/conf docker/production/certbot/www
dc run --rm -p 80:80 --entrypoint certbot certbot certonly --standalone \
  -d "$APP_DOMAIN" --agree-tos --no-eff-email -m admin@"$APP_DOMAIN"
```

Дальше сертификат продлевает контейнер `certbot` (`certbot renew --webroot` дважды в сутки
через `/.well-known/acme-challenge/` на порту 80). После продления nginx нужно перечитать
сертификат. Добавьте в crontab хоста (`crontab -e`):

```
0 4 * * * cd /opt/exclaude && docker compose -f docker-compose.prod.yml exec -T nginx nginx -s reload >/dev/null 2>&1
```

Для домена с `www` добавьте `-d www.$APP_DOMAIN` и `www.${APP_DOMAIN}` в `server_name`
(`docker/production/nginx.conf`).

### Запуск

```bash
dc up -d mysql redis
dc run --rm app php artisan migrate --force
dc up -d
dc ps                                     # все сервисы healthy
dc exec app php artisan downloads:status
curl -fsS https://$APP_DOMAIN/up
```

Создать пользователя (регистрация на сайте) и при необходимости выдать тариф:

```bash
dc exec app php artisan tinker --execute 'App\Models\User::where("email","user@example.com")->first()->forceFill(["plan"=>"premium","plan_expires_at"=>now()->addMonth()])->save();'
```

## 3. Обновление

```bash
cd /opt/exclaude
git pull
export APP_VERSION=$(git rev-parse --short HEAD)    # тег образа; старый остаётся для отката
dc build
dc run --rm app php artisan migrate --force
dc up -d                                             # пересоздаёт изменившиеся контейнеры
dc exec app php artisan queue:restart                # воркеры дорабатывают текущую задачу и выходят
dc ps && curl -fsS https://$APP_DOMAIN/up
```

- `stop_grace_period: 60s`: воркер получает SIGTERM и даёт текущей загрузке завершиться.
- Задача, оборванная SIGKILL, вернётся в очередь через `retry_after` и начнётся заново.
- Откат: `APP_VERSION=<предыдущий тег> dc up -d`. Если в релизе была миграция, выполните перед
  этим `dc run --rm app php artisan migrate:rollback --step=1 --force`.
- `APP_VERSION` в shell перекрывает значение из `.env`. Чтобы версия сохранилась, запишите её в `.env`.

### Обновление yt-dlp

YouTube регулярно меняется, и старый yt-dlp перестаёт работать: ошибки `failed` или
`blocked` в `downloads.log`. Версия закреплена в `docker/Dockerfile` вместе с SHA-256:

```bash
V=2026.11.15    # версия из https://github.com/yt-dlp/yt-dlp/releases
curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/download/$V/SHA2-256SUMS | grep ' yt-dlp_linux$'
```

Подставьте `YTDLP_VERSION` и `YTDLP_SHA256` в `docker/Dockerfile`, затем `dc build && dc up -d`.
Сборка упадёт, если хеш не совпал. Так же обновляется Deno: `DENO_VERSION`, `DENO_SHA256`;
хеш берётся из `deno-x86_64-unknown-linux-gnu.zip.sha256sum` в релизе.

## 4. Резервное копирование

Что копировать:
- **база MySQL** — пользователи, задачи;
- **`storage/app/webcam`** — снимки пользователей.

Загрузки (`storage/app/downloads`) временные, копировать не нужно. Redis тоже не нужен: в нём
очередь, кеш и сессии. При потере пользователи перелогинятся, а незавершённые задачи переведёт
в `failed` очистка.

```bash
sudo mkdir -p /var/backups/exclaude && sudo chown $USER /var/backups/exclaude
B=/var/backups/exclaude; D=$(date +%F)

# MySQL (консистентный снимок InnoDB без блокировки)
dc exec -T mysql sh -c 'exec mysqldump --single-transaction --routines --triggers --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' | gzip > $B/db-$D.sql.gz

# Файлы пользователей
dc exec -T app tar czf - -C /var/www/html/storage app/webcam app/public > $B/storage-$D.tgz

# Хранить 14 дней
find $B -type f -mtime +14 -delete
```

Те же команды можно поставить в cron (`0 3 * * *`, `cd /opt/exclaude` и полные
`docker compose -f docker-compose.prod.yml` вместо `dc`). Копию стоит дополнительно
отправлять за пределы сервера (S3, rclone, другой VPS) и хранить `.env` отдельно,
потому что без `APP_KEY` не расшифровать сессии и cookie.

### Восстановление

```bash
dc stop app queue-worker scheduler
gunzip -c /var/backups/exclaude/db-2026-11-19.sql.gz | dc exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
dc start app
dc exec -T app tar xzf - -C /var/www/html/storage < /var/backups/exclaude/storage-2026-11-19.tgz
dc exec app chown -R www-data:www-data /var/www/html/storage/app
dc start queue-worker scheduler
dc exec app php artisan downloads:status
```

Проверяйте восстановление на тестовом сервере хотя бы раз в квартал.

## 5. Повседневные операции

| Задача | Команда |
|---|---|
| Состояние | `dc ps`, `dc exec app php artisan downloads:status` |
| Логи загрузок | `dc exec app tail -f storage/logs/downloads-$(date +%F).log` |
| Логи контейнера | `dc logs -f --tail=100 queue-worker` |
| Упавшие jobs | `dc exec app php artisan queue:failed`, `queue:retry all`, `queue:flush` |
| Очистка вручную | `dc exec app php artisan downloads:cleanup` |
| Больше воркеров | `QUEUE_WORKERS=4` в `.env`, `dc up -d` |
| Режим обслуживания | `dc exec app php artisan down --retry=60` / `up` |
| Только CC-лицензии | `DOWNLOADS_LICENSE_POLICY=creative_commons` в `.env`, `dc up -d` |

### Признаки проблем

- **`oldest pending` в `downloads:status` растёт:** воркеров мало или они упали. Проверьте
  `dc ps queue-worker` и добавьте воркеров.
- **Много `blocked`:** платформа ограничивает IP сервера. Снизьте лимиты, подождите,
  обновите yt-dlp.
- **Свободного места меньше 10 %:** уменьшите `DOWNLOADS_FILE_TTL` или `DOWNLOADS_MAX_QUEUED`
  либо увеличьте диск. Каждая активная задача может занять до `max_filesize` своего тарифа.
