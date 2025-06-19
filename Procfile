# Web process with Apache
web: vendor/bin/heroku-php-apache2 public/

# Release phase commands (runs before the new release is deployed)
release: \
  echo "Running release phase..." && \
  php artisan config:cache && \
  php artisan route:cache && \
  php artisan view:cache && \
  php artisan storage:link

# Optional: Database migrations (uncomment after initial deployment)
# release: php artisan migrate --force

# Debug command (uncomment if needed)
# debug: php -v && composer --version