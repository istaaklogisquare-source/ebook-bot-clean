<?php
$env = parse_ini_file(__DIR__ . '.env');

$DISCORD_TOKEN = $env['DISCORD_TOKEN'];
$STRIPE_SECRET_KEY = $env['STRIPE_SECRET_KEY'];

?>
