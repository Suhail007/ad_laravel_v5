web: vendor/bin/heroku-php-apache2 -p $PORT public/
release: |
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php artisan storage:link