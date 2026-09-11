FROM php:8.2-apache

# Enable Apache mod_rewrite & install pdo_mysql extension
RUN a2enmod rewrite \
    && docker-php-ext-install pdo_mysql

# Copy project files to Apache root
COPY . /var/www/html/

# Ensure data directory exists and set proper permissions for SQLite & uploads
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/data

EXPOSE 80
