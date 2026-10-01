FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        sqlite \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        gd \
        mbstring \
        zip \
        intl \
        opcache \
    && pecl install sodium \
    && docker-php-ext-enable sodium \
    && apk del libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev icu-dev oniguruma-dev

COPY . /app
WORKDIR /app

RUN mkdir -p storage/files storage/thumbs storage/e2e storage/tmp storage/logs storage/cache \
    && chown -R www-data:www-data storage

ENV DEFAULT_LANGUAGE=en

EXPOSE 8080

CMD ["php", "-d", "variables_order=EGPCS", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
