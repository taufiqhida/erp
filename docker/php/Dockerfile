# syntax=docker/dockerfile:1

##############################################
# Stage 1 — build aset frontend (Vite/Vue)
##############################################
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY public ./public
RUN npm run build

##############################################
# Stage 2 — install dependency PHP (composer)
##############################################
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --ignore-platform-reqs

##############################################
# Stage 3 — runtime: satu image isi Nginx + PHP-FPM + supervisord.
# Sengaja SATU container (bukan Nginx & PHP-FPM terpisah) supaya tidak perlu
# shared volume rumit buat public/build hasil Vite — cukup untuk kebutuhan
# staging. Kalau nanti perlu scale horizontal, pisahkan lagi + pakai object
# storage/shared volume buat public/build.
##############################################
FROM php:8.3-fpm-alpine AS runtime

# Extension yang dipakai project ini:
# - pdo_mysql: DB utama (project ini MySQL 8 ONLY — migrasi pakai
#   REGEXP_SUBSTR & ALTER...MODIFY COLUMN ENUM, tidak jalan di MariaDB versi
#   lama/SQLite. Jangan ganti ke MariaDB tanpa cek dulu.)
# - gd, zip, intl: dibutuhkan maatwebsite/excel (PhpSpreadsheet) &
#   phpoffice/phpword buat generate Excel/Word (Import/Export & Surat).
# - bcmath: umum dipakai kalkulasi Laravel.
# - opcache: performa produksi.
RUN apk add --no-cache \
        nginx supervisor \
        icu-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql gd zip intl bcmath opcache \
    && docker-php-ext-enable opcache \
    && apk del $PHPIZE_DEPS

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
