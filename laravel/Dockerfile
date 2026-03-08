# ─────────────────────────────────────────────────────────────────────────────
# Stage 1: Install Composer dependencies
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS composer

RUN apk add --no-cache \
    git \
    curl \
    unzip \
    libpq \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /build

# Copy composer files first (cache layer)
COPY composer.json ./

# Install base Laravel project (no custom files yet)
RUN composer create-project laravel/laravel:^11.0 /laravel --prefer-dist --no-interaction

# Copy our composer.json to install additional packages
COPY composer.json /laravel/composer.json
WORKDIR /laravel
RUN composer update --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

# ─────────────────────────────────────────────────────────────────────────────
# Stage 2: Application image
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS app

# Install runtime system dependencies
RUN apk add --no-cache \
    libpq \
    libpq-dev \
    redis \
    && docker-php-ext-install pdo pdo_pgsql pgsql pcntl \
    && apk del libpq-dev

# Install Redis PHP extension
RUN apk add --no-cache --virtual .build-deps autoconf g++ make \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www

# Copy the full Laravel installation from build stage
COPY --from=composer /laravel .

# Overlay our custom application files on top of the base Laravel install
COPY app/ ./app/
COPY config/ ./config/
COPY database/ ./database/
COPY routes/ ./routes/
COPY bootstrap/ ./bootstrap/

# Set permissions
RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 /var/www/storage \
    && chmod -R 775 /var/www/bootstrap/cache

# PHP-FPM configuration
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

EXPOSE 9000

CMD ["php-fpm"]
