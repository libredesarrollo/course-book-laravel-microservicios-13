<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name', 'user-service'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
