FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-0 \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

WORKDIR /var/www/html

COPY app/ /var/www/html/

RUN mkdir -p /var/www/html/data/files \
    && chown -R www-data:www-data /var/www/html/data