# Web process
web: vendor/bin/heroku-php-apache2 public/

# Release command (runs on every deploy)
release: |
  /app/.heroku/php/bin/php artisan config:cache
  /app/.heroku/php/bin/php artisan route:cache
  /app/.heroku/php/bin/php artisan view:cache
  /app/.heroku/php/bin/php artisan storage:link

# Uncomment after initial deployment
# /app/.heroku/php/bin/php artisan migrate --force