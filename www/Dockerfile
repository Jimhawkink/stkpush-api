# Use official PHP 8.2 image
FROM php:8.2-apache

# Install required extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copy all files to web root
COPY . /var/www/html/

# Expose port 10000 for Render
EXPOSE 10000
