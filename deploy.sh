#!/bin/bash

# Railway Laravel Deployment Script
# This script handles the deployment process for Laravel on Railway

set -e

echo "🚀 Starting Laravel deployment on Railway..."

# Install Composer if not available
if ! command -v composer &> /dev/null; then
    echo "📦 Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Install PHP dependencies
echo "📦 Installing PHP dependencies..."
composer install --no-interaction --optimize-autoloader --no-dev

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "⚙️ Creating .env file..."
    cp .env.example .env 2>/dev/null || {
        echo "⚠️ No .env.example found, creating basic .env..."
        cat > .env << EOF
APP_NAME=Laravel
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://\${RAILWAY_PUBLIC_DOMAIN}
LOG_CHANNEL=stderr
LOG_LEVEL=debug
DB_CONNECTION=mysql
DB_HOST=\${MYSQLHOST}
DB_PORT=\${MYSQLPORT}
DB_DATABASE=\${MYSQLDATABASE}
DB_USERNAME=\${MYSQLUSER}
DB_PASSWORD=\${MYSQLPASSWORD}
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_LIFETIME=120
EOF
    }
fi

# Generate application key if not set
if grep -q '^APP_KEY=$' .env; then
    echo "🔑 Generating application key..."
    php artisan key:generate --no-interaction
fi

# Install Node.js dependencies and build assets if package.json exists
if [ -f package.json ]; then
    echo "📦 Installing Node.js dependencies..."
    npm install
    
    echo "🔨 Building assets..."
    npm run build
fi

# Cache configuration for production
echo "⚡ Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Create storage link
echo "🔗 Creating storage link..."
php artisan storage:link

# Run database migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force --no-interaction

echo "✅ Deployment setup complete!"
echo "🚀 Starting Laravel server on port \$PORT..."

# Start the application
exec php artisan serve --host=0.0.0.0 --port=$PORT 