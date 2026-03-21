<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Dashboard API endpoints
    Route::prefix('/api/dashboard')->group(function () {
        Route::get('/metrics', [DashboardController::class, 'getMetrics']);
        Route::get('/visitors-chart', [DashboardController::class, 'getVisitorsChart']);
        Route::get('/channels', [DashboardController::class, 'getChannels']);
        Route::get('/sources', [DashboardController::class, 'getSources']);
        Route::get('/utm-campaigns', [DashboardController::class, 'getUtmCampaigns']);
        Route::get('/pages', [DashboardController::class, 'getTopPages']);
        Route::get('/entry-pages', [DashboardController::class, 'getEntryPages']);
        Route::get('/exit-pages', [DashboardController::class, 'getExitPages']);
        Route::get('/countries', [DashboardController::class, 'getCountries']);
        Route::post('/countries-geojson', [DashboardController::class, 'postCountriesGeoJson']);
        Route::get('/regions', [DashboardController::class, 'getRegions']);
        Route::get('/cities', [DashboardController::class, 'getCities']);
        Route::get('/browsers', [DashboardController::class, 'getBrowsers']);
        Route::get('/operating-systems', [DashboardController::class, 'getOperatingSystems']);
        Route::get('/devices', [DashboardController::class, 'getDevices']);
        Route::get('/details/{category}', [DashboardController::class, 'getDetails']);
        Route::post('/live-visitors', [DashboardController::class, 'getLiveVisitors']);
    });
});

require __DIR__.'/settings.php';
