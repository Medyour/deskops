FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM php:8.3-apache-bookworm@sha256:b79a53c3fb1dd4b9f350a8d99d84951512b6c21044e61bbecfee8af030b3722e AS application
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libsqlite3-dev libonig-dev libicu-dev libzip-dev unzip \
    && docker-php-ext-install -j2 pdo_pgsql pdo_sqlite mbstring intl zip opcache \
    && a2enmod rewrite \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
COPY .do/apache.conf /etc/apache2/sites-available/000-default.conf
COPY .do/php.ini /usr/local/etc/php/conf.d/deskops.ini
RUN sh .do/build-production.sh php

FROM node:24.19.0-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS assets
WORKDIR /app
COPY package.json package-lock.json .node-version .do/build-production.sh ./
RUN npm install --global npm@11.17.0
COPY resources ./resources
COPY vite.config.js ./
COPY --from=application /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN sh build-production.sh assets

FROM application
COPY --from=assets /app/public/build ./public/build
RUN chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
CMD ["sh", ".do/start-web.sh"]
