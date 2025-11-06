# Use PHP 8.2 with Apache
FROM php:8.2-cli

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git unzip libpng-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli zip

# Copy all project files into /app
WORKDIR /app
COPY . .

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php
RUN php composer.phar install --no-dev --optimize-autoloader || true

# Start your bot
CMD ["php", "bot2.php"]

