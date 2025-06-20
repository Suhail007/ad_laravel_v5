# Stage 1: Install dependencies and build assets
FROM php:8.2-cli-alpine3.18 as builder

WORKDIR /app

# Install system dependencies for building
RUN apk add --no-cache \
    build-base \
    curl \
    git \
    libpng-dev \
    zlib-dev \
    libzip-dev \
    oniguruma-dev \
    zip \
    unzip \
    nodejs-current \
    npm

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql exif pcntl bcmath gd zip

# Get latest Composer
COPY --from=composer:2.5 /usr/bin/composer /usr/bin/composer

# Copy the entire application source code
COPY . .

# Install composer dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Create a dummy sqlite file for the build process
RUN mkdir -p database && touch database/database.sqlite

# Clear any cached configuration now that dependencies are installed
RUN php artisan config:clear
RUN php artisan cache:clear

# Install and build assets
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
RUN npm run build

# Stage 2: Final image for production
FROM php:8.2-cli-alpine3.18

WORKDIR /app

# Install runtime dependencies and dev packages for extensions
RUN apk add --no-cache \
    libpng-dev \
    zlib-dev \
    libzip-dev \
    oniguruma-dev

# Install PHP extensions required for runtime
RUN docker-php-ext-install pdo_mysql exif pcntl bcmath gd zip

# Copy application files and dependencies from builder stage
COPY --from=builder /app .

# Set permissions for Laravel
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache && \
    chmod -R 775 /app/storage /app/bootstrap/cache

# Railway provides the start command, so no CMD here.
# The start command in railway.toml will be used. 