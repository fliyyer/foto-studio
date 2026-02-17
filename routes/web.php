<?php

use Illuminate\Support\Facades\Route;

Route::any('/{any?}', function () {
    abort(403, 'Forbidden');
})->where('any', '.*');
