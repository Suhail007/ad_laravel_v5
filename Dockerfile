# Use the official PHP-FPM image as a base.
FROM php:8.2-fpm-alpine

# Set the working directory in the container.
WORKDIR /app

# Install essential system dependencies for Laravel, including Caddy.
RUN apk add --no-cache \
    caddy \
    libpng-dev \
    zlib-dev \
    libzip-dev \
    oniguruma-dev \
    curl \
    git \
    zip \
    unzip \
    nodejs-current \
    npm

# Install the required PHP extensions.
RUN docker-php-ext-install pdo_mysql exif pcntl bcmath gd zip

# Get the latest version of Composer.
COPY --from=composer:2.5 /usr/bin/composer /usr/bin/composer

# Copy the application source code into the container.
COPY . .

# Install Composer dependencies.
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Install NPM dependencies and build frontend assets.
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
RUN npm run build

# Copy the Caddy web server configuration.
COPY Caddyfile /etc/caddy/Caddyfile

# Copy the start script and make it executable.
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Set correct permissions for Laravel's storage directories.
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

# The command that will be run when the container starts.
CMD ["/usr/local/bin/start.sh"] 