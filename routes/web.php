<?php

use App\Http\Controllers\OrderPdfController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/order/{id}/pdf', [OrderPdfController::class, 'generateOrderPdf']);
