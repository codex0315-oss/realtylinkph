# Render has no native PHP runtime — Node, Python, Ruby, Go, Rust and Elixir
# only — so the Laravel backend ships as a container instead.
#
# Build context is this directory. On Render, set the service's Root Directory
# to `realtylinkph-backend` so these paths line up.
FROM php:8.3-apache

# ── System libraries and PHP extensions ──────────────────────────────────────
# pdo_pgsql/pgsql : Render Postgres.
# gd              : intervention/image declares ext-gd as a platform
#                   requirement, so composer install refuses without it —
#                   even though nothing in app/ actually imports it.
# zip, bcmath     : required by Laravel's dependency tree.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip gd bcmath \
    && rm -rf /var/lib/apt/lists/*

# ── Apache ───────────────────────────────────────────────────────────────────
# Laravel's front controller is public/index.php, and the pretty-URL rewrite
# in public/.htaccess is ignored unless AllowOverride permits it — without
# this every route except / returns 404.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && printf '<Directory /var/www/html/public>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel

# Uploads are gated by PHP before Laravel ever sees them; the app validates
# photos at max:10240 (10 MB), so these must sit above that or large uploads
# fail with an empty request instead of a validation message.
RUN printf 'upload_max_filesize = 12M\npost_max_size = 20M\nmemory_limit = 256M\n' \
        > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ── Dependencies ─────────────────────────────────────────────────────────────
# Copied before the source so this layer stays cached across deploys that
# only change application code. --no-scripts because artisan isn't here yet.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

# ── Application ──────────────────────────────────────────────────────────────
COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]

# Overridden on the worker/scheduler services with a different artisan command.
CMD ["apache2-foreground"]
