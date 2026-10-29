<?php

use App\Http\Controllers\SsoController;
use App\Http\Controllers\WebLoginController;
use Demo\Microservices\Observability\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return view('welcome');
    }

    return redirect()->route('login');
})->name('home');

Route::get('/login', [WebLoginController::class, 'show'])->name('login')->middleware('guest');
Route::post('/login', [WebLoginController::class, 'login'])->middleware('throttle:6,1');
Route::get('/logout', [WebLoginController::class, 'logout'])->name('logout');
Route::post('/logout', [WebLoginController::class, 'logout']);

Route::get('/authorize', [SsoController::class, 'authorize'])->name('sso.authorize');

Route::get('/health', [HealthController::class, 'index']);
