FROM php:8.1-fpm-bullseye

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       libcurl4-openssl-dev \
       libfreetype6-dev \
       libjpeg62-turbo-dev \
       libpng-dev \
       libzip-dev \
       libonig-dev \
       libxml2-dev \
       unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
       curl \
       gd \
       mbstring \
       mysqli \
       pdo \
       pdo_mysql \
       xml \
       zip \
       opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-monkeybot.ini
COPY docker/php/entrypoint.sh /usr/local/bin/monkeybot-entrypoint
COPY application/config /defaults/application/config

RUN chmod +x /usr/local/bin/monkeybot-entrypoint \
    && mkdir -p /var/www/html \
    && chown -R www-data:www-data /defaults

WORKDIR /var/www/html

ENTRYPOINT ["monkeybot-entrypoint"]
CMD ["php-fpm"]
