FROM php:8.4-fpm

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN export DEBIAN_FRONTEND=noninteractive 

RUN apt-get update -y
RUN apt-get install -y --force-yes libssl-dev curl libcurl4-gnutls-dev libxml2-dev libicu-dev libmcrypt4 libmemcached11 openssl libzip-dev
RUN apt-get install -y ca-certificates

RUN apt-get install -y gnupg gnupg2 apt-transport-https wget
#software-properties-common 
RUN apt-get install -y libgeos-dev postgis zip unzip
#RUN apt-get install -y libfreetype-dev libjpeg62-turbo-dev libpng-dev 
RUN apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng16-16

#CONFIGURAÇÕES DO OPCACHE
RUN docker-php-ext-install opcache

#LIBS EXTRAS
RUN apt-get install -y libpq-dev libicu-dev libbz2-dev
RUN docker-php-ext-install bcmath bz2 intl curl xml exif pgsql pdo_pgsql zip

# Cliente Redis nativo para Symfony Cache (cache.adapter.redis)
RUN pecl install redis \
    && docker-php-ext-enable redis

# INSTALAÇÃO DO COMPOSER
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
#cli commom mcrypt zip
RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install -j$(nproc) gd

# Espelha o UID/GID do host no www-data para manter permissao consistente em bind mounts.
RUN groupmod -o -g "${HOST_GID}" www-data \
    && usermod -o -u "${HOST_UID}" -g "${HOST_GID}" www-data

# Aumentar memory_limit para processar grandes volumes de dados
# RUN echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/docker-php-memlimit.ini

USER www-data
CMD ["php-fpm"]
