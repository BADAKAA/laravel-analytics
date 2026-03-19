<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

// Analytics API endpoints - stateless, no middleware
Route::post('/pageview', ApiController::class)->name('pageview');
