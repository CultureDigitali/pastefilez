FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        su-exec \
        sqlite \
        libzip-dev libzip \
        libpng-dev libpng \
        libjpeg-turbo-dev libjpeg-turbo \
        libwebp-dev libwebp \
        freetype-dev freetype \
        icu-dev icu-libs \
        libcurl-dev curl-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd zip intl curl mbstring pdo_sqlite opcache fileinfo \
    && apk del libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev icu-dev libcurl-dev curl-dev oniguruma-dev

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY . /app
WORKDIR /app

RUN mkdir -p storage/files storage/thumbs storage/e2e storage/tmp storage/logs storage/cache \
    && chown -R www-data:www-data /app

RUN printf '%s\n' \
        'upload_max_filesize=100M' \
        'post_max_size=101M' \
        'memory_limit=512M' \
        'max_execution_time=300' \
        'max_input_time=300' \
        'default_charset=UTF-8' \
        > /usr/local/etc/php/conf.d/pastefilez.ini

ENV DEFAULT_LANGUAGE=en \
    PHP_CLI_SERVER_WORKERS=8

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php", "-d", "variables_order=EGPCS", "-S", "0.0.0.0:8080", "-t", "public"]