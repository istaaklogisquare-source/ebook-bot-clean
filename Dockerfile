# Use official PHP 8 image
FROM php:8.2-cli

# Copy all project files into container
COPY . .

# Command to run your bot
CMD ["php", "bot.php"]
