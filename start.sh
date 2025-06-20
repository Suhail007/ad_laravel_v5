#!/bin/sh

# This script is the entrypoint for the container.
# It prepares the Laravel application and then starts the servers.

# Enable job control
set -m

# Run essential Laravel optimizations for production.
echo "Running Laravel optimizations..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start the PHP-FPM process in the background.
echo "Starting PHP-FPM..."
php-fpm &

# Start the Caddy web server in the foreground.
echo "Starting Caddy..."
caddy run --config /etc/caddy/Caddyfile --adapter caddyfile 