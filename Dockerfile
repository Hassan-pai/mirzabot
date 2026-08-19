# MirzaBot – Railway/Docker image
# Based on https://github.com/mahdiMGF2/mirzabot (AGPL-3.0-or-later)
# SPDX-License-Identifier: AGPL-3.0-or-later
FROM php:8.2-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Asia/Tehran \
    APP_DIR=/var/www/html \
    MIRZA_HOME=/opt/mirza \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# ---------------------------------------------------------------- system deps
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl wget git unzip zip tzdata coreutils procps \
        cron supervisor \
        mariadb-client \
        libcurl4-openssl-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libwebp-dev libonig-dev libxml2-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# --------------------------------------------------------------- php extensions
# mbstring, curl, json, dom, simplexml, xmlwriter, iconv, fileinfo, zlib
# are already built into the official php:8.2 image.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli gd zip intl bcmath exif sockets opcache \
 && docker-php-ext-enable opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ------------------------------------------------------------------ apache setup
RUN a2enmod rewrite headers expires remoteip \
 && a2dissite 000-default

COPY docker/apache-hardening.conf /etc/apache2/conf-available/zz-mirza.conf
COPY docker/apache-mirza.conf     /etc/apache2/sites-available/mirza.conf
COPY docker/php-mirza.ini         /usr/local/etc/php/conf.d/zz-mirza.ini
RUN a2enconf zz-mirza && a2ensite mirza

# --------------------------------------------------------------- app + vendors
WORKDIR ${APP_DIR}
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . ${APP_DIR}
RUN composer dump-autoload --optimize --no-dev

# ------------------------------------------------------------- runtime scripts
COPY docker/ ${MIRZA_HOME}/
RUN chmod +x ${MIRZA_HOME}/*.sh \
 && install -m 0755 ${MIRZA_HOME}/mysqldump-shim.sh /usr/local/bin/mysqldump \
 && mkdir -p ${MIRZA_HOME}/supervisor.d /var/log/mirza \
 && chown -R www-data:www-data ${APP_DIR} /var/log/mirza

EXPOSE 8080
ENTRYPOINT ["/opt/mirza/entrypoint.sh"]
CMD ["supervisord"]

