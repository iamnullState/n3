FROM php:8.5-cli

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql

WORKDIR /app
