FROM php:8.2-cli

WORKDIR /app

COPY . /app

# Install composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    rm composer-setup.php

# ✅ Install dependencies if composer.json present
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader; fi

CMD ["php", "bot.php"]
