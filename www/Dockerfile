# Use official PHP 8.2 Apache image
FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_mysql mysqli

# Make Apache listen on port 10000 for Render
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf

# Copy your PHP project files into the container
COPY . /var/www/html/

# Log Apache output to Render logs
RUN ln -sf /dev/stdout /var/log/apache2/access.log \
 && ln -sf /dev/stderr /var/log/apache2/error.log

# Expose port 10000
EXPOSE 10000
