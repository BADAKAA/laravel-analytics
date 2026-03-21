<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('/api/dashboard')->group(function () {
        Route::post('/aggregate', [DashboardController::class, 'getAggregateData']);
        
        Route::post('/countries-geojson', [DashboardController::class, 'postCountriesGeoJson']);
        Route::post('/live-visitors', [DashboardController::class, 'getLiveVisitors']);
        
        Route::get('/details/{category}', [DashboardController::class, 'getDetails']);
    });
});

require __DIR__.'/settings.php';
