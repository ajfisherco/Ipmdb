FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring pdo_mysql \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-ipmdb.conf /etc/apache2/conf-available/ipmdb.conf
RUN a2enconf ipmdb

COPY ipmdb /var/www/html/ipmdb

RUN chown -R www-data:www-data /var/www/html/ipmdb
