# Render has no native PHP runtime — Node, Python, Ruby, Go, Rust and Elixir
# only — so the Laravel backend ships as a container instead.
#
# Build context is this directory. On Render, set the service's Root Directory
# to `realtylinkph-backend` so these paths line up.
# 8.4, not 8.3: composer.lock was resolved on PHP 8.4 and pins Symfony 8.x,
# which requires >= 8.4.1. composer install refuses the lock on anything older.
FROM php:8.4-apache

# ── System libraries and PHP extensions ──────────────────────────────────────
# pdo_pgsql/pgsql : Render Postgres.
# gd              : intervention/image, which resizes listing photos on
#                   upload (PropertyPhotoService). --with-webp so a WebP
#                   upload — an accepted mime — can be decoded too.
# zip, bcmath     : required by Laravel's dependency tree.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip gd bcmath opcache \
    && rm -rf /var/lib/apt/lists/*

# ── OPcache ──────────────────────────────────────────────────────────────────
# The official image ships with opcache compiled but NOT enabled, so without
# this every request re-parses and re-compiles the whole framework from source
# — measured at roughly 150–250 ms per request on Render's shared CPU, which
# was most of the "warm" latency. The code inside a container never changes
# (a deploy builds a new image), so timestamp validation is switched off too:
# no stat() call per included file. jit_buffer_size stays 0 on purpose — the
# JIT gains nothing for a request/response app and has had stability issues.
RUN printf 'opcache.enable=1\n\
opcache.enable_cli=0\n\
opcache.validate_timestamps=0\n\
opcache.memory_consumption=128\n\
opcache.interned_strings_buffer=16\n\
opcache.max_accelerated_files=20000\n\
opcache.save_comments=1\n\
realpath_cache_size=4096K\n\
realpath_cache_ttl=600\n' \
        > /usr/local/etc/php/conf.d/opcache.ini

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
