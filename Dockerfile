FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev libxml2-dev libzip-dev libimage-exiftool-perl \
    && docker-php-ext-install pdo_sqlite mbstring dom zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

COPY . /var/www/html
COPY docker/servername.conf /etc/apache2/conf-enabled/servername.conf
COPY docker/uploads.ini /usr/local/etc/php/conf.d/n3-uploads.ini
COPY docker/entrypoint.sh /usr/local/bin/n3-entrypoint

RUN mkdir -p /var/www/data/plugins /var/www/data/branding \
    && chown -R www-data:www-data /var/www/data /var/www/html \
    && chmod 0755 /usr/local/bin/n3-entrypoint

ENTRYPOINT ["n3-entrypoint"]
CMD ["apache2-foreground"]

EXPOSE 80
