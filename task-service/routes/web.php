<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tasks');

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:6,1');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

Route::middleware('auth.user-service')->group(function () {
    Route::resource('tasks', TaskController::class);
});
