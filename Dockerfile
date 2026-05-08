# Use an official PHP runtime with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install required packages for SQLite
RUN apt-get update && apt-get install -y libsqlite3-dev

# Install PDO and PDO_SQLite extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Copy your backend files to the Apache document root
COPY . /var/www/html/

# Create the data directory and set permissions so PHP can write to the SQLite file
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

# Expose port 80 (Render forwards traffic to the port your app binds to, or 80 by default for Apache)
EXPOSE 80
