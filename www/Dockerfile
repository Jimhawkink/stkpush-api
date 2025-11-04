# Use official PHP 8.2 image with Apache
FROM php:8.2-apache

# Install system dependencies and PDO extensions properly
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_mysql mysqli

# Make Apache listen on port 10000 (Render uses this)
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf

# Copy all files into Apache web directory
COPY . /var/www/html/

# Optional: make logs visible in Render logs
RUN ln -sf /dev/stdout /var/log/apache2/access.log \
 && ln -sf /dev/stderr /var/log/apache2/error.log

# Expose port 10000 for Render
EXPOSE 10000
