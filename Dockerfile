# Production image for the BBB Drupal 11 site (Northflank).
# Docroot is web/. Dependencies are installed at build time via Composer
# (vendor/, core, and contrib are gitignored, so they are fetched here).
# syntax=docker/dockerfile:1

FROM composer:2 AS composer

FROM php:8.3-apache-bookworm AS app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    APACHE_DOCUMENT_ROOT=/var/www/html/web

# System packages + PHP extension build dependencies.
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip default-mysql-client \
      libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
      libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql opcache zip intl bcmath mbstring \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer binary.
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Apache: serve from web/, allow Drupal's .htaccess, enable rewrite.
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers expires

# Production PHP settings (opcache, limits).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-drupal.ini

WORKDIR /var/www/html

# Copy the project (see .dockerignore) and install dependencies.
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-progress

# Env-driven settings + writable files dir.
COPY docker/settings.php web/sites/default/settings.php
RUN mkdir -p web/sites/default/files \
    && chown -R www-data:www-data web/sites/default/files \
    && chmod 644 web/sites/default/settings.php

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
