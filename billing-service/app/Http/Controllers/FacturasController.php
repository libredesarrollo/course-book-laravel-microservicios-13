<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Demo\Microservices\UserServiceClient\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacturasController extends Controller
{
    public function index(Request $request): View
    {
        $user = AuthenticatedUser::fromRequest($request);

        $invoices = Invoice::query()->when($user !== null, fn ($query) => $query->forUser($user->id))->orderByDesc('created_at')->paginate(10);

        return view('facturas.index', [
            'invoices' => $invoices,
            'user' => $user,
        ]);
    }
}
