<?php
$DISCORD_TOKEN = getenv('DISCORD_TOKEN');
$STRIPE_SECRET_KEY = getenv('STRIPE_SECRET_KEY');

// For debug
if (!$DISCORD_TOKEN) {
    die("❌ DISCORD_TOKEN not found in environment\n");
}
?>
