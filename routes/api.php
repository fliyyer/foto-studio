<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AddonController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\StudioController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('api.token')->group(function () {
    Route::delete('/studios/{id}', [StudioController::class, 'destroy']);
    Route::post('/studios', [StudioController::class, 'store']);
    Route::put('/studios/{id}', [StudioController::class, 'update']);

    Route::post('/studios/{studioId}/packages', [PackageController::class, 'store']);
    Route::post('/studios/{studioId}/packages/{id}', [PackageController::class, 'update']);
    Route::delete('/studios/{studioId}/packages/{id}', [PackageController::class, 'destroy']);

    Route::post('/studios/{studioId}/packages/{packageId}/addons', [AddonController::class, 'store']);
    Route::post('/studios/{studioId}/packages/{packageId}/addons/{id}', [AddonController::class, 'update']);
    Route::delete('/studios/{studioId}/packages/{packageId}/addons/{id}', [AddonController::class, 'destroy']);
});

Route::get('/studios', [StudioController::class, 'index']);
Route::get('/studios/{id}', [StudioController::class, 'show']);
Route::get('/studios/{studioId}/packages', [PackageController::class, 'index']);
Route::get('/studios/{studioId}/packages/{id}', [PackageController::class, 'show']);
Route::get('/studios/{studioId}/packages/{packageId}/addons', [AddonController::class, 'index']);
Route::get('/studios/{studioId}/packages/{packageId}/addons/{id}', [AddonController::class, 'show']);

Route::get('/studios/{studioId}/packages/{packageId}/available-slots', [BookingController::class, 'availableSlots']);
Route::post('/studios/{studioId}/packages/{packageId}/bookings', [BookingController::class, 'store']);
Route::get('/bookings/{invoiceNumber}', [BookingController::class, 'show']);
