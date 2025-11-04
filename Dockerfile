# Use official PHP image
FROM php:8.2-cli

# Install dependencies (MySQL, zip, etc.)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql

# Set working directory
WORKDIR /app

# Copy all files into the container
COPY . .

# Install Composer globally
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

# If composer.json exists, install dependencies
RUN if [ -f composer.json ]; then composer install --no-interaction --no-dev; fi

# Start your PHP bot when the container runs
CMD ["php", "bot.php"]
