<?php

use App\Http\Controllers\Api\AuthController;
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
});

  Route::get('/studios', [StudioController::class, 'index']);
  Route::get('/studios/{id}', [StudioController::class, 'show']);
