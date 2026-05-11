FROM php:8.3-fpm-alpine

#RUN set -ex \
#&& apk --no-cache add \
#postgresql-dev

# Install Postgre PDO
RUN apk add \
    && apk add libpq-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pgsql pdo_pgsql

WORKDIR /var/www/app

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Configure Nginx
COPY nginx/nginx.conf /etc/nginx/nginx.conf

USER root

RUN chmod 777 -R /var/www/app