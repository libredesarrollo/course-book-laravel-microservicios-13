<?php

namespace App\Http\Controllers;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
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
     */
    public function store(Request $request, IdentityProvider $users): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $token = $users->issueToken($credentials + ['device_name' => 'task-service-web']);
        } catch (UserServiceErrorException) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Credenciales inválidas.']);
        }

        $request->session()->regenerate();
        $request->session()->put('api_token', $token);

        return redirect()->intended(route('tasks.index'));
    }

    /**
     * Cierra sesión y revoca el token en el user-service.
     */
    public function destroy(Request $request, IdentityProvider $users): RedirectResponse
    {
        $token = $request->session()->get('api_token');

        // Revocar en el user-service es lo que lo hace inutilizable de verdad:
        // si solo lo borramos de la sesión, el token sigue vivo en su tabla.
        if (is_string($token) && $token !== '') {
            rescue(fn () => $users->revokeToken($token), report: false);
        }

        $request->session()->forget('api_token');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
