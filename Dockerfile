# Step 1: Use official PHP image
FROM php:8.2-cli

# Step 2: Set working directory
WORKDIR /app

# Step 3: Copy all project files
COPY . .

# Step 4: Install dependencies if composer.json exists
RUN if [ -f "composer.json" ]; then php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php \
    && php composer.phar install; fi

# Step 5: Start your bot
CMD ["php", "bot.php"]
