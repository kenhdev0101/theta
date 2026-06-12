# theta app runtime — php-fpm serves the telemetry API; the same image runs
# horizon/scheduler/artisan via command override. Dependency installation runs
# inside this container (`docker compose -p theta run --rm app composer ...`);
# host-level Composer installs are not the implementation path.
FROM php:8.3-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        rsync \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        pcntl \
        intl \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The repo bind-mount is owned by the invoking host user; git inside the
# container must still be able to read it for composer metadata.
RUN git config --global --add safe.directory /var/www/html

WORKDIR /var/www/html
