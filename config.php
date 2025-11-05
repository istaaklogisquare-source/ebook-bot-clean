<?php
$envPath = __DIR__ . '/.env';  // ✅ Notice the '/' before .env

if (!file_exists($envPath)) {
    die("❌ .env file not found at: $envPath");
}

$env = parse_ini_file($envPath);

if ($env === false) {
    die("❌ Failed to parse .env file");
}

// ✅ Access values safely
$DISCORD_TOKEN = $env['DISCORD_TOKEN'] ?? null;
$STRIPE_SECRET_KEY = $env['STRIPE_SECRET_KEY'] ?? null;

// Just to verify (remove in production)
echo "Discord Token: " . $DISCORD_TOKEN . PHP_EOL;
echo "Stripe Key: " . $STRIPE_SECRET_KEY . PHP_EOL;
