FROM docker.io/php:8.3-apache

# install packages (unzip for Composer installation, icu for intl extension)
RUN apt-get update && apt-get install -y acl unzip libicu-dev

# install PHP extensions
RUN docker-php-ext-configure pdo_mysql && docker-php-ext-install pdo_mysql
RUN docker-php-ext-configure opcache && docker-php-ext-install opcache
RUN docker-php-ext-configure intl && docker-php-ext-install intl
RUN pecl install redis && docker-php-ext-enable redis

# install Composer
COPY docker/install-composer.sh /tmp
RUN /tmp/install-composer.sh

COPY docker/entrypoint.sh /usr/local/bin/stargazer-entrypoint
RUN chmod +x /usr/local/bin/stargazer-entrypoint

# enable required Apache modules
RUN a2enmod rewrite headers

ENTRYPOINT ["/usr/local/bin/stargazer-entrypoint"]
CMD ["apache2-foreground"]
