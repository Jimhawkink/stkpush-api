# Use official PHP image
FROM php:8.2-cli

# Set working directory
WORKDIR /app

# Copy project files
COPY ./www /app

# Expose port (Render sets $PORT)
ENV PORT=10000
EXPOSE $PORT

# Start built-in PHP server
CMD ["php", "-S", "0.0.0.0:10000", "-t", "."]
