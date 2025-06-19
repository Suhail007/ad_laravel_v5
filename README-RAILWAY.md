# Railway Deployment Guide

This guide explains how to deploy this Laravel application on Railway.

## Prerequisites

- A GitHub account
- A Railway account (sign up at [railway.app](https://railway.app/))
- A MySQL database (provided by Railway)

## Deployment Steps

### 1. Fork and Clone the Repository

1. Fork this repository to your GitHub account
2. Clone your forked repository to your local machine

### 2. Set Up Railway Project

1. Sign in to [Railway](https://railway.app/)
2. Click "New Project" and select "Deploy from GitHub repo"
3. Select your forked repository
4. Railway will automatically detect the `Dockerfile` and start building your application

### 3. Configure Environment Variables

After the initial deployment, you'll need to set up the following environment variables in the Railway dashboard:

1. Go to your project in Railway
2. Click on the "Variables" tab
3. Add the following required variables:

```
APP_NAME=Your App Name
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:YOUR_APP_KEY
APP_URL=https://YOUR_RAILWAY_URL.railway.app

DB_CONNECTION=mysql
DB_HOST=YOUR_RAILWAY_DB_HOST
DB_PORT=YOUR_RAILWAY_DB_PORT
DB_DATABASE=YOUR_RAILWAY_DB_NAME
DB_USERNAME=YOUR_RAILWAY_DB_USER
DB_PASSWORD=YOUR_RAILWAY_DB_PASSWORD

SESSION_DRIVER=file
CACHE_DRIVER=file
QUEUE_CONNECTION=sync

LOG_CHANNEL=stderr
LOG_LEVEL=info
```

### 4. Set Up Database

1. In the Railway dashboard, click on "New" and select "Database"
2. Select "MySQL"
3. Once created, go to the database settings and copy the connection URL
4. Update your environment variables with the database credentials

### 5. Run Database Migrations

1. Go to the "Deployments" tab in Railway
2. Click on the three dots next to your latest deployment
3. Select "Run Command"
4. Enter: `php artisan migrate --force`

### 6. Set Up Storage Link

1. In the same "Run Command" interface, run:
   ```
   php artisan storage:link
   ```

### 7. (Optional) Set Up Custom Domain

1. Go to the "Settings" tab in Railway
2. Under "Custom Domains", click "Add Custom Domain"
3. Follow the instructions to verify domain ownership

## Local Development

To run this project locally:

1. Copy `.env.example` to `.env` and update the values
2. Install dependencies:
   ```bash
   composer install
   npm install
   ```
3. Generate application key:
   ```bash
   php artisan key:generate
   ```
4. Run database migrations:
   ```bash
   php artisan migrate
   ```
5. Start the development server:
   ```bash
   php artisan serve
   ```

## Troubleshooting

- If you encounter permission issues, run:
  ```bash
  chmod -R 775 storage bootstrap/cache
  chown -R www-data:www-data storage bootstrap/cache
  ```

- To view logs in Railway, go to the "Logs" tab in your project dashboard

## Support

For support, please open an issue in the GitHub repository.
