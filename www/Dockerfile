# Use official PHP 8.2 image
FROM php:8.2-apache

# Install required extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Configure Apache to listen on Render's port (10000)
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf

# Copy all files to Apache web root
COPY . /var/www/html/

# Expose port 10000 for Render
EXPOSE 10000
