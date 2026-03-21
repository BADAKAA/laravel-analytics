<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::put('/sites/{site}', [SiteController::class, 'update'])->name('sites.update');

    Route::prefix('/api/dashboard')->group(function () {
        Route::post('/aggregate', [DashboardController::class, 'getAggregateData']);
        
        Route::post('/countries-geojson', [DashboardController::class, 'postCountriesGeoJson']);
        Route::post('/live-visitors', [DashboardController::class, 'getLiveVisitors']);
        
        Route::get('/details/{category}', [DashboardController::class, 'getDetails']);
    });

            Route::prefix('/api/sites')->name('sites.api.')->group(function () {
                Route::get('/', [SiteController::class, 'apiIndex'])->name('index');
                Route::post('/', [SiteController::class, 'apiStore'])->name('store');
                Route::put('/{site}', [SiteController::class, 'apiUpdate'])->name('update');
            });
});

require __DIR__.'/settings.php';
