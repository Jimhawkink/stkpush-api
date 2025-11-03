# Use official PHP 8.2 Apache image
FROM php:8.2-apache

# Install common PHP extensions (for MySQL)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy project files into Apache web directory
COPY ./www /var/www/html/

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
