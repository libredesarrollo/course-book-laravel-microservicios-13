<?php

use App\Http\Controllers\TaskController;
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoCallbackController;
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoLogoutController;
use Demo\Microservices\UserServiceClient\Http\Controllers\SsoStartController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tasks');

Route::get('/health-ui', function () {
    return response('ok', 200)->header('Content-Type', 'text/plain');
});

Route::get('/sso/start', SsoStartController::class)->name('sso.start');
Route::get('/sso/callback', SsoCallbackController::class)->name('sso.callback');
Route::post('/logout', SsoLogoutController::class)->name('logout');

Route::middleware('auth.user-service')->group(function () {
    Route::resource('tasks', TaskController::class);
});
