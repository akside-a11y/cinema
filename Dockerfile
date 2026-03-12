# Use PHP CLI
FROM php:8.2-cli

# Install required extensions and git/unzip
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    && docker-php-ext-install sockets \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy project files
COPY . /app

# Install composer
RUN curl -sS https://getcomposer.org/installer | php

# Install PHP dependencies
RUN php composer.phar install --no-interaction --prefer-dist

# Expose your WebSocket port
EXPOSE 8181

# Run the WebSocket server
CMD ["php", "server.php"]
