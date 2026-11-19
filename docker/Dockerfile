# syntax=docker/dockerfile:1
#
# Targets:
#   dev   – local development, code is bind-mounted (docker-compose.yml)
#   prod  – self-contained application image: code, vendor/, built assets (docker-compose.prod.yml)
#   web   – nginx with the public/ directory of the prod image (docker-compose.prod.yml)
#
# The same image runs PHP-FPM (app), the queue worker and the scheduler; only the command differs.

# ---------------------------------------------------------------------------
# base: PHP 8.5 + yt-dlp + ffmpeg + deno
# ---------------------------------------------------------------------------
FROM phpdockerio/php:8.5-fpm AS base
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get -y --no-install-recommends install \
        imagemagick php8.5-imagick php8.5-gd php8.5-mysql php8.5-redis php8.5-bcmath php8.5-gmp php8.5-zmq php8.5-zip \
        ffmpeg unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# yt-dlp (standalone binary) and Deno (JS runtime yt-dlp uses for YouTube's player challenges).
# Versions are pinned and verified by SHA-256; to update, see docs/DEPLOYMENT.md ("Обновление yt-dlp").
ARG YTDLP_VERSION=2026.10.26
ARG YTDLP_SHA256=767ba9dce7052aa196f8b12652f433d76de128ba168966fa84b6394d5e6c6795
ARG DENO_VERSION=2.9.8
ARG DENO_SHA256=e890975bc2549807bbcc0b584ffafda72cc7c93349dd99c80483dd784f6c4609
RUN curl -fsSL -o /usr/local/bin/yt-dlp "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp_linux" \
    && echo "${YTDLP_SHA256}  /usr/local/bin/yt-dlp" | sha256sum -c - \
    && chmod 0755 /usr/local/bin/yt-dlp \
    && curl -fsSL -o /tmp/deno.zip "https://github.com/denoland/deno/releases/download/v${DENO_VERSION}/deno-x86_64-unknown-linux-gnu.zip" \
    && echo "${DENO_SHA256}  /tmp/deno.zip" | sha256sum -c - \
    && unzip -q /tmp/deno.zip -d /usr/local/bin \
    && chmod 0755 /usr/local/bin/deno \
    && rm /tmp/deno.zip

COPY docker/php_fpm/zz-pool.conf /etc/php/8.5/fpm/pool.d/zz-pool.conf

EXPOSE 9000
CMD ["php-fpm8.5", "-F"]

# ---------------------------------------------------------------------------
# dev: code, vendor/ and php.ini are bind-mounted by docker-compose.yml
# ---------------------------------------------------------------------------
FROM base AS dev
RUN apt-get update \
    && apt-get -y --no-install-recommends install git nano \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# vendor: production Composer dependencies
# ---------------------------------------------------------------------------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi

# ---------------------------------------------------------------------------
# assets: Vite build (public/build)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# prod: the application image
# ---------------------------------------------------------------------------
FROM base AS prod
COPY docker/production/php.ini /etc/php/8.5/fpm/conf.d/99-overrides.ini
COPY docker/production/php.ini /etc/php/8.5/cli/conf.d/99-overrides.ini
COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

# Only storage/ and bootstrap/cache are writable, and only by www-data (no 777).
RUN rm -rf docker tests node_modules \
    && mkdir -p storage/app/downloads storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R root:root /var/www/html \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache

# ---------------------------------------------------------------------------
# web-base: nginx whose workers may read (not write) files owned by the app's group
# (www-data, gid 33 in the PHP image), needed for X-Accel-Redirect of finished downloads.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web-base
RUN addgroup -g 33 -S appfiles && addgroup nginx appfiles

# ---------------------------------------------------------------------------
# web: nginx serving public/ and proxying PHP to the app service
# ---------------------------------------------------------------------------
FROM web-base AS web
COPY docker/production/nginx.conf /etc/nginx/templates/default.conf.template
COPY --from=prod /var/www/html/public /var/www/html/public
