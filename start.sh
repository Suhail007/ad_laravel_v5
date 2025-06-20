#!/bin/sh

# This script is the entrypoint for the container.
# It starts both php-fpm and the Caddy web server.

# Enable job control
set -m

# Start the PHP-FPM process in the background
php-fpm &

# Start the Caddy web server in the foreground.
# Caddy will automatically proxy requests to PHP-FPM.
caddy run --config /etc/caddy/Caddyfile --adapter caddyfile 