@extends('layouts.app')

@section('title', 'Entrar')

@section('content')
    <div class="card narrow">
        <h2 style="font-size:1.3rem;margin-top:0">Iniciar sesión</h2>
        <p class="sub" style="margin-bottom:20px">
            Las credenciales se envían al <strong>user-service</strong>, que es quien tiene
            la tabla de usuarios. El task-service no las ve.
        </p>

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       required autofocus autocomplete="username">
                @error('email') <p class="err">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password"
                       required autocomplete="current-password">
                @error('password') <p class="err">{{ $message }}</p> @enderror
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Entrar</button>
            </div>
        </form>

        <p class="hint">
            Usuarios de prueba (creados por el seeder del user-service):<br>
            <code>ana.torres@example.com</code> · <code>luis.ramirez@example.com</code>
            — contraseña <code>password</code>
        </p>
    </div>
@endsection