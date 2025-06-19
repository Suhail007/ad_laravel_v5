<?php

use App\Http\Controllers\OrderPdfController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health check endpoint
Route::get('/health', HealthController::class);

// Application routes
Route::get('/', function () {
    return view('welcome');
});

// Order PDF generation
Route::get('/order/{id}/pdf', [OrderPdfController::class, 'generateOrderPdf']);
