<?php

return [
    'url' => env('WOOCOMMERCE_URL'),
    'consumer_key' => env('WOOCOMMERCE_CONSUMER_KEY'),
    'consumer_secret' => env('WOOCOMMERCE_CONSUMER_SECRET'),
    'options' => [
        'wp_api' => true,
        'version' => 'wc/v3',
    ],
];
