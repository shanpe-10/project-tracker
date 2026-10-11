FROM php:8.4-apache-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl libpq-dev libzip-dev libonig-dev libxml2-dev libicu-dev unzip \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo 'deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main' > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends postgresql-client-18 \
    && docker-php-ext-install -j2 pdo_pgsql mbstring zip intl opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x deploy/start.sh \
    && php artisan package:discover --ansi

COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/tracker.ini
COPY deploy/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
ENV PG_DUMP_BINARY=/usr/lib/postgresql/18/bin/pg_dump
EXPOSE 10000
CMD ["/var/www/html/deploy/start.sh"]
