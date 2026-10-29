<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión - User Service</title>
</head>
<body>
    <h1>Iniciar sesión</h1>
    <p>Servicio central de autenticación (IdP).</p>

    @if ($errors->any())
        <div>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf
        @if($client)
            <input type="hidden" name="client" value="{{ $client }}">
        @endif
        @if($redirectUri)
            <input type="hidden" name="redirect_uri" value="{{ $redirectUri }}">
        @endif

        <div>
            <label for="email">Correo</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
        </div>

        <div>
            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
        </div>

        <button type="submit">Entrar</button>
    </form>

    <p>Credenciales demo: ana.torres@example.com / password · luis.ramirez@example.com / password</p>
</body>
</html>
