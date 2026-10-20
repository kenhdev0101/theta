<?php

use App\Http\Controllers\CockpitController;
use App\Http\Controllers\ConvexCockpitController;
use Illuminate\Support\Facades\Route;

// Operator cockpit — read-only observability surface (loopback-only via compose).
// Route::redirect (not a closure) so `php artisan route:cache` in the deploy
// hook can serialize it.
Route::redirect('/', '/cockpit');
Route::get('/cockpit', [CockpitController::class, 'index'])->name('cockpit');
Route::get('/cockpit/data', [CockpitController::class, 'data'])->name('cockpit.data');
Route::get('/cockpit/convex', [ConvexCockpitController::class, 'index'])->name('cockpit.convex');
Route::get('/cockpit/convex/data', [ConvexCockpitController::class, 'data'])->name('cockpit.convex.data');
