<?php

namespace App\Http\Controllers;

use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    /**
     * Inicia sesión contra el user-service y guarda el token en la sesión.
     *
     * El task-service nunca ve una contraseña: solo la reenvía al user-service
     * y guarda el token que le devuelven.
     *
     * La lógica de la sesión (emitir el token, regenerar el identificador de
     * sesión, guardarlo) vive en el paquete: es la misma en cualquier
     * consumidor del user-service. Aquí solo se validan las credenciales y se
     * decide a dónde ir.
     */
    public function store(Request $request, TokenSession $session): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $session->login($request, $credentials, 'task-service-web');
        } catch (UserServiceErrorException) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Credenciales inválidas.']);
        }

        return redirect()->intended(route('tasks.index'));
    }

    /**
     * Cierra sesión y revoca el token en el user-service.
     */
    public function destroy(Request $request, TokenSession $session): RedirectResponse
    {
        $session->logout($request);

        return redirect()->route('login');
    }
}
