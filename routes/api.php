<?php

use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware([IdempotencyMiddleware::class])->group(function () {
    Route::post('/users', [UserController::class, 'store']);
});
