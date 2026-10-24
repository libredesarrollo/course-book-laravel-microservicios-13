<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Demo\Microservices\UserServiceClient\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = AuthenticatedUser::fromRequest($request);

        return new JsonResponse([
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
            ],
            'invoices' => [
                ['id' => 1, 'amount' => 99.99, 'status' => 'paid'],
                ['id' => 2, 'amount' => 49.50, 'status' => 'pending'],
            ],
        ]);
    }
}
