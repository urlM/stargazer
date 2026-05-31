FROM docker.io/php:8.3-apache

# install packages (unzip for Composer installation, icu for intl extension)
RUN apt-get update && apt-get install -y unzip libicu-dev

# install PHP extensions
RUN docker-php-ext-configure pdo_mysql && docker-php-ext-install pdo_mysql
RUN docker-php-ext-configure opcache && docker-php-ext-install opcache
RUN docker-php-ext-configure intl && docker-php-ext-install intl
RUN pecl install redis && docker-php-ext-enable redis

# install Composer
COPY docker/install-composer.sh /tmp
RUN /tmp/install-composer.sh

# enable required Apache modules
RUN a2enmod rewrite headers
