<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Demo\Microservices\UserServiceClient\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = AuthenticatedUser::fromRequest($request);

        $invoices = Invoice::query()
            ->when($user !== null, fn ($query) => $query->forUser($user->id))
            ->orderByDesc('created_at')
            ->get(['id', 'amount', 'status']);

        return new JsonResponse([
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
            ],
            'invoices' => $invoices,
        ]);
    }
}
