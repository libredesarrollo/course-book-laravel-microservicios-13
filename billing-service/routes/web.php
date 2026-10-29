<?php

use App\Http\Controllers\FacturasController;
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoCallbackController;
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoLogoutController;
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoStartController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/facturas');

Route::get('/sso/start', SsoStartController::class)->name('sso.start');
Route::get('/sso/callback', SsoCallbackController::class)->name('sso.callback');
Route::post('/logout', SsoLogoutController::class)->name('logout');

Route::middleware('auth.user-service')->group(function () {
    Route::get('/facturas', [FacturasController::class, 'index'])->name('facturas.index');
});
