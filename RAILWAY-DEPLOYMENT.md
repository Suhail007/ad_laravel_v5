# Railway Deployment Guide for Laravel

This guide will help you deploy your Laravel application to Railway.com without Docker issues, similar to traditional PHP hosting.

## 🚀 Quick Deploy

### Option 1: Deploy via Railway CLI
```bash
# Install Railway CLI
npm install -g @railway/cli

# Login to Railway
railway login

# Deploy your project
railway up
```

### Option 2: Deploy via GitHub Integration
1. Push your code to GitHub
2. Connect your GitHub repository to Railway
3. Railway will automatically detect the Laravel application and deploy it

## ⚙️ Configuration Files

The following files have been configured for Railway deployment:

### `railway.toml`
- Uses Nixpacks builder (Railway's default)
- Configures production environment variables
- Sets up health checks

### `nixpacks.toml`
- Installs PHP 8.2 with required extensions
- Installs Composer and Node.js
- Handles dependency installation and build process

### `railway.json`
- Alternative configuration using Railway's JSON format
- Defines build and deploy commands
- Sets environment variables

### `deploy.sh`
- Custom deployment script for advanced setup
- Handles Composer installation, environment setup, and migrations

## 🔧 Environment Variables

Railway will automatically provide these environment variables:

- `RAILWAY_PUBLIC_DOMAIN` - Your app's public domain
- `PORT` - Port to run the application on
- `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD` - Database credentials

## 📦 Required Services

### Database (MySQL)
1. Add a MySQL service to your Railway project
2. Railway will automatically inject database environment variables
3. The application will run migrations automatically

### Optional: Redis (for caching)
If you want to use Redis for caching:
1. Add a Redis service to your Railway project
2. Update your `.env` variables:
   ```
   CACHE_DRIVER=redis
   SESSION_DRIVER=redis
   REDIS_HOST=${REDISHOST}
   REDIS_PORT=${REDISPORT}
   REDIS_PASSWORD=${REDISPASSWORD}
   ```

## 🏥 Health Checks

The application includes a health check endpoint at `/health` that:
- Verifies database connectivity
- Checks Redis connection (if configured)
- Returns JSON status response

## 🔍 Troubleshooting

### Common Issues:

1. **Composer not found**: The `nixpacks.toml` now installs Composer automatically
2. **Database connection errors**: Ensure MySQL service is added and environment variables are set
3. **Permission errors**: Railway handles file permissions automatically
4. **Build failures**: Check the build logs in Railway dashboard

### Debug Mode:
To enable debug mode temporarily:
1. Go to Railway dashboard
2. Navigate to your service
3. Add environment variable: `APP_DEBUG=true`
4. Redeploy the service

## 📁 File Structure

```
ad_laravel_v5/
├── app/                    # Application logic
├── config/                 # Configuration files
├── database/               # Migrations and seeders
├── resources/              # Views and assets
├── routes/                 # Application routes
├── storage/                # File storage
├── public/                 # Public assets
├── railway.toml           # Railway configuration
├── nixpacks.toml          # Nixpacks build configuration
├── railway.json           # Alternative Railway config
├── deploy.sh              # Custom deployment script
└── Procfile               # Process definition
```

## 🚀 Deployment Process

1. **Build Phase**:
   - Install PHP 8.2 with extensions
   - Install Composer and Node.js
   - Install PHP dependencies (`composer install`)
   - Install Node.js dependencies (`npm install`)
   - Build frontend assets (`npm run build`)

2. **Setup Phase**:
   - Create `.env` file from `.env.example`
   - Generate application key
   - Cache configuration files
   - Create storage link

3. **Start Phase**:
   - Run database migrations
   - Start Laravel development server

## 🔄 Continuous Deployment

Railway supports automatic deployments:
- Push to your main branch triggers automatic deployment
- Railway will rebuild and redeploy your application
- Health checks ensure the application is running correctly

## 📊 Monitoring

Railway provides:
- Real-time logs
- Performance metrics
- Health check status
- Automatic restarts on failure

## 🛠️ Local Development

To test locally with Railway environment:
```bash
# Install dependencies
composer install
npm install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# Start development server
php artisan serve
```

## 📞 Support

If you encounter issues:
1. Check Railway deployment logs
2. Verify environment variables are set correctly
3. Ensure all required services are added
4. Check the health endpoint: `https://your-app.railway.app/health`

---

**Note**: This configuration is optimized for Railway's PHP environment and should work similar to traditional PHP hosting services like Hostinger. 