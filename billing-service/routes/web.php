<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FacturasController;
use App\Http\Controllers\SsoCallbackController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/facturas');

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:6,1');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
Route::get('/sso/callback', [SsoCallbackController::class, 'callback'])->name('sso.callback');

Route::middleware('auth.user-service')->group(function () {
    Route::get('/facturas', [FacturasController::class, 'index'])->name('facturas.index');
});
