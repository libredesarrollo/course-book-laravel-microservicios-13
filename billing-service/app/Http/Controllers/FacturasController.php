<?php

namespace App\Http\Controllers;

use Demo\Microservices\UserServiceClient\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacturasController extends Controller
{
    public function index(Request $request): View
    {
        $user = AuthenticatedUser::fromRequest($request);

        $invoices = [
            ['id' => 101, 'amount' => 149.99, 'status' => 'paid'],
            ['id' => 102, 'amount' => 59.00, 'status' => 'pending'],
            ['id' => 103, 'amount' => 24.99, 'status' => 'paid'],
        ];

        return view('facturas.index', [
            'invoices' => $invoices,
            'user' => $user,
        ]);
    }
}
