FROM phpdockerio/php:8.1-fpm
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get -y --no-install-recommends install supervisor cron imagemagick php8.1-imagick php8.1-gd php8.1-mysql nano php8.1-redis php8.1-bcmath php8.1-gmp php8.1-zmq git php8.1-zip \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# YouTube audio extraction for /parser: yt-dlp (standalone binary), ffmpeg (mp3 conversion)
# and Deno (JS runtime yt-dlp needs to solve YouTube's player challenges).
# yt-dlp is intentionally unpinned: YouTube changes often; update with `yt-dlp -U` or rebuild.
RUN apt-get update \
    && apt-get -y --no-install-recommends install ffmpeg \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && curl -fsSL -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux \
    && chmod a+rx /usr/local/bin/yt-dlp \
    && curl -fsSL -o /tmp/deno.zip https://github.com/denoland/deno/releases/latest/download/deno-x86_64-unknown-linux-gnu.zip \
    && unzip -q /tmp/deno.zip -d /usr/local/bin \
    && chmod a+rx /usr/local/bin/deno \
    && rm /tmp/deno.zip

# Composer
#COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

#RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html/storage \
 && chown -R www-data:www-data /var/www/html/bootstrap/cache \
 && chown -R www-data:www-data /var/www/html/storage/logs

RUN rm -rf /var/www/html/docker \
 && chmod -R 777 /var/www/html/bootstrap \
 && chmod -R 777 /var/www/html/storage \
 && chmod -R 777 /var/www/html/storage/logs

EXPOSE 9000

CMD ["php-fpm8.1", "-F"]