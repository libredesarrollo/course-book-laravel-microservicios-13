@extends('layouts.app')

@section('content')
    <div class="panel" style="max-width: 520px; margin: 20px auto;">
        <div class="panel-header">
            <h1 style="margin: 0;">Acceder a Facturas</h1>
            <p style="margin: 6px 0 0; color: var(--muted);">Usa las mismas credenciales del user-service.</p>
        </div>
        <div class="panel-body">
            <form class="form" method="POST" action="{{ route('login') }}">
                @csrf
                <label>
                    Correo electrónico
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                    @error('email')
                        <span class="error">{{ $message }}</span>
                    @enderror
                </label>
                <label>
                    Contraseña
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <button class="btn" type="submit">Entrar</button>
            </form>
        </div>
    </div>
@endsection
