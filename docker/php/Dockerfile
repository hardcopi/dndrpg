FROM php:8.4-fpm

# Extension set mirrors what hermes has enabled for this app. GD is built with
# WebP because hermes's is, and CharacterLook decodes the look library
# (assets/looks/v1/**.webp) with it; without it a look cannot be baked.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libzip-dev libxslt1-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli gd zip exif xsl sockets opcache \
    && rm -rf /var/lib/apt/lists/*

# Make the container's www-data match host user richard (uid 1000, gid 1001) so
# files created by PHP are editable on the host and vice versa. richard's gid is
# 1001, NOT 1000 - assuming otherwise silently breaks write access.
RUN groupmod -g 1001 www-data && usermod -u 1000 -g 1001 www-data \
    && chown -R www-data:www-data /var/www
