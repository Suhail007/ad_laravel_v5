web: php artisan serve --host=0.0.0.0 --port=$PORT

release: php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan storage:link && php artisan migrate --force --no-interaction

migrate: php artisan migrate --force --no-interaction