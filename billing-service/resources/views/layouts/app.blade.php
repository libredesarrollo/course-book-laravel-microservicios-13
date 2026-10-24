<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing · Demo Microservicios</title>
    <style>
        :root {
            --bg: #f8fafc;
            --panel: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --border: #e2e8f0;
            --primary: #2563eb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }
        header {
            background: var(--panel);
            border-bottom: 1px solid var(--border);
        }
        .nav {
            max-width: 960px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
        }
        .nav-left {
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .brand {
            font-weight: 600;
        }
        .links {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .links a {
            color: var(--muted);
            text-decoration: none;
            padding: 6px 8px;
            border-radius: 8px;
        }
        .links a.active {
            color: var(--text);
            background: rgba(37, 99, 235, 0.08);
        }
        .nav-right {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--muted);
        }
        .user {
            color: var(--text);
        }
        .btn-link {
            border: 0;
            background: transparent;
            color: var(--muted);
            font: inherit;
            cursor: pointer;
        }
        main {
            max-width: 960px;
            margin: 0 auto;
            padding: 28px 20px 60px;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 30px 60px -40px rgba(15, 23, 42, 0.8);
        }
        .panel-header {
            padding: 22px 28px 0;
        }
        .panel-body {
            padding: 18px 28px 28px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid var(--border);
        }
        th {
            color: var(--muted);
            font-weight: 500;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            font-size: 13px;
            color: var(--muted);
        }
        .badge.paid {
            color: #166534;
            border-color: rgba(22, 101, 52, 0.4);
        }
        .badge.pending {
            color: #92400e;
            border-color: rgba(146, 64, 14, 0.4);
        }
        .form {
            display: grid;
            gap: 16px;
            max-width: 420px;
        }
        label {
            display: grid;
            gap: 8px;
            color: var(--muted);
        }
        input {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
            font: inherit;
        }
        .btn {
            justify-self: start;
            border: 1px solid transparent;
            background: var(--primary);
            color: white;
            border-radius: 999px;
            padding: 9px 18px;
            font: inherit;
            cursor: pointer;
        }
        .error {
            color: #dc2626;
        }
    </style>
</head>
<body>
    <header>
        <nav class="nav">
            <div class="nav-left">
                <div class="brand">Billing</div>
                <div class="links">
                    <a href="{{ env('TASK_SERVICE_URL', 'http://127.0.0.1:8001') }}/tasks">Tareas</a>
                    <a class="@if(request()->routeIs('facturas.*')) active @endif" href="{{ route('facturas.index') }}">Facturas</a>
                </div>
            </div>
            <div class="nav-right">
                @php
                    $authUser = \Demo\Microservices\UserServiceClient\AuthenticatedUser::fromRequest(request());
                @endphp
                @if($authUser)
                    <span>Hola, <span class="user">{{ $authUser->name }}</span></span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn-link">Cerrar sesión</button>
                    </form>
                @else
                    <a href="{{ route('login') }}">Iniciar sesión</a>
                @endif
            </div>
        </nav>
    </header>
    <main>
        @yield('content')
    </main>
</body>
</html>
