# Use official PHP 8.2 Apache image
FROM php:8.2-apache

# Enable commonly used PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy all project files into Apache web root
COPY ./www /var/www/html/

# Expose port 80
EXPOSE 80

# Start Apache server
CMD ["apache2-foreground"]
