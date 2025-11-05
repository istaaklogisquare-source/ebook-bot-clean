# ✅ Use official PHP image
FROM php:8.2-cli

# Set working directory
WORKDIR /app

# Copy project files
COPY . /app

# ✅ Install required system packages
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    libssl-dev \
    libzip-dev \
    zip \
    && docker-php-ext-install zip

# ✅ Install Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

# ✅ Install dependencies (with full composer output)
RUN if [ -f "composer.json" ]; then COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader --no-interaction; fi

# ✅ Start your bot
CMD ["php", "bot.php"]
