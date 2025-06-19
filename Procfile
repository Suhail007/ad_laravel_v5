# Web process
web: vendor/bin/heroku-php-apache2 public/

# Release command (runs on every deploy)
release: \
  php artisan config:cache && \
  php artisan route:cache && \
  php artisan view:cache && \
  php artisan storage:link

# Uncomment after initial deployment
# release: php artisan migrate --force