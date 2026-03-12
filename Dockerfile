FROM php:8.2-cli

# Install PHP sockets extension
RUN docker-php-ext-install sockets

# Copy project files
COPY . /app
WORKDIR /app

# Install composer dependencies
RUN curl -sS https://getcomposer.org/installer | php
RUN php composer.phar install

# Expose the port your WebSocket server uses
EXPOSE 8181

# Start your server
CMD ["php", "server.php"]