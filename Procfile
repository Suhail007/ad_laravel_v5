# Web process
web: php artisan serve --host=0.0.0.0 --port=$PORT

# Release phase commands (all in one line)
release: php -r "file_exists('.env') || copy('.env.example', '.env');" && php artisan key:generate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan storage:link && php artisan migrate --force --no-interaction

# Migration command
migrate: php artisan migrate --force --no-interaction