<?php

use App\Http\Controllers\Api\TaskController as ApiTaskController;
use Illuminate\Support\Facades\Route;

// Se prefija con api. para no chocar con los nombres de routes/web.php
// (tasks.index, tasks.store, ... ya están registrados por la UI).
Route::apiResource('tasks', ApiTaskController::class)->names('api.tasks');
