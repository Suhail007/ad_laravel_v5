<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Automattic\WooCommerce\Client;

class WooCommerceServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(Client::class, function ($app) {
            $config = config('woocommerce');
            return new Client(
                $config['url'],
                $config['consumer_key'],
                $config['consumer_secret'],
                $config['options']
            );
        });
    }

    public function boot()
    {
        //
    }
}
