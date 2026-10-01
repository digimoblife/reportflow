FROM php:8.3-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    build-base \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    freetype-dev \
    postgresql-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo \
        pdo_pgsql \
        pgsql \
        posix \
        zip

# Install Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Clean build dependencies
RUN apk del $PHPIZE_DEPS

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Production image (docker-compose.prod.yml): the code is baked in, dev dependencies are left out, nothing is bind-mounted.
FROM base AS prod

ENV COMPOSER_ALLOW_SUPERUSER=1
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
# The code never changes under a running container, so opcache need not re-check files.
RUN echo "opcache.validate_timestamps = 0" > /usr/local/etc/php/conf.d/zz-prod.ini

COPY . /var/www/html
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/backup bootstrap/cache \
    && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan filament:assets --ansi \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]

# Development image (the default target, used by docker-compose.yml with the code bind-mounted).
FROM base AS dev

# Adjust permissions for www-data
RUN chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
