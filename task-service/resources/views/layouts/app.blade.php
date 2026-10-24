<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@yield('title', 'Tareas') — Task Service</title>
<style>
  :root{
    --bg:#f6f8fa; --card:#ffffff; --line:#d8dee4; --text:#1f2328; --muted:#59636e;
    --accent:#0969da; --accent-bg:#ddf4ff; --ok:#1a7f37; --ok-bg:#dafbe1;
    --warn:#9a6700; --warn-bg:#fff8c5; --err:#cf222e; --err-bg:#ffebe9;
  }
  @media (prefers-color-scheme: dark){
    :root{
      --bg:#0d1117; --card:#161b22; --line:#2b3440; --text:#e6edf3; --muted:#9198a1;
      --accent:#58a6ff; --accent-bg:#121d2f; --ok:#3fb950; --ok-bg:#0f2b17;
      --warn:#d29922; --warn-bg:#2b2410; --err:#f85149; --err-bg:#2d1416;
    }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);
       font:16px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
  .wrap{max-width:940px;margin:0 auto;padding:32px 20px 72px}
  header.top{display:flex;align-items:center;justify-content:space-between;
             gap:16px;flex-wrap:wrap;padding-bottom:18px;border-bottom:1px solid var(--line);margin-bottom:28px}
  header.top h1{font-size:1.3rem;margin:0;letter-spacing:-.01em}
  header.top .right{display:flex;align-items:center;gap:14px}
  .whoami{font-size:.86rem;color:var(--muted)}
  .whoami strong{color:var(--text)}
  h2{font-size:1.6rem;margin:0 0 6px;letter-spacing:-.02em}
  .sub{color:var(--muted);margin:0 0 24px}
  a{color:var(--accent)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:22px 24px;margin-bottom:20px}
  .card.narrow{max-width:420px;margin:40px auto}
  table{width:100%;border-collapse:collapse;font-size:.94rem}
  th,td{text-align:left;padding:11px 10px;border-bottom:1px solid var(--line)}
  th{font-size:.74rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted)}
  tbody tr:last-child td{border-bottom:none}
  td.actions{text-align:right;white-space:nowrap}
  .badge{display:inline-block;font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;
         padding:5px 9px;border-radius:999px}
  .b-pending{background:var(--warn-bg);color:var(--warn)}
  .b-completed{background:var(--ok-bg);color:var(--ok)}
  .btn{display:inline-block;font:500 .88rem/1 inherit;padding:9px 15px;border-radius:8px;
       border:1px solid var(--line);background:var(--card);color:var(--text);
       text-decoration:none;cursor:pointer}
  .btn:hover{border-color:var(--accent);color:var(--accent)}
  .btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
  .btn-primary:hover{opacity:.9;color:#fff}
  .btn-danger:hover{border-color:var(--err);color:var(--err)}
  .btn-sm{padding:6px 10px;font-size:.8rem}
  .row-actions{display:flex;gap:14px;justify-content:flex-end}
  .field{margin-bottom:18px}
  label{display:block;font-weight:600;font-size:.88rem;margin-bottom:6px}
  input[type=text],input[type=email],input[type=password],select{width:100%;padding:10px 12px;font:inherit;
       border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--text)}
  input:focus,select:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:transparent}
  .err{color:var(--err);font-size:.84rem;margin-top:6px}
  .alert{padding:12px 15px;border-radius:9px;font-size:.9rem;margin-bottom:20px;border:1px solid}
  .a-ok{background:var(--ok-bg);color:var(--ok);border-color:transparent}
  .a-warn{background:var(--warn-bg);color:var(--warn);border-color:transparent}
  .a-err{background:var(--err-bg);color:var(--err);border-color:transparent}
  .empty{text-align:center;padding:44px 20px;color:var(--muted)}
  .form-actions{display:flex;gap:10px;align-items:center;padding-top:6px}
  dl.meta{display:grid;grid-template-columns:auto 1fr;gap:10px 22px;margin:0}
  dl.meta dt{color:var(--muted);font-size:.86rem}
  dl.meta dd{margin:0;font-weight:500}
  .pager{display:flex;gap:10px;align-items:center;justify-content:center;margin-top:22px;
         font-size:.88rem;color:var(--muted)}
  code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.87em;
       background:var(--bg);border:1px solid var(--line);padding:1px 6px;border-radius:5px}
  .hint{font-size:.82rem;color:var(--muted);margin:14px 0 0;line-height:1.5}
</style>
</head>
<body>
<div class="wrap">

@php
    // El middleware AuthenticateWithUserService (paquete del cliente) deja
    // aquí la identidad que confirmó el user-service por HTTP. En /login
    // simplemente no existe.
    $currentUser = \Demo\Microservices\UserServiceClient\AuthenticatedUser::fromRequest(request());
@endphp

<header class="top">
    <h1>Task Service</h1>
    <nav style="display:flex; align-items:center; gap:14px; margin-left:18px;">
        <a href="{{ route('tasks.index') }}">Mis tareas</a>
        <a href="{{ env('BILLING_SERVICE_URL', 'http://127.0.0.1:8002') }}/facturas">Facturas</a>
    </nav>
    <div class="right" style="margin-left:auto;">
        @if ($currentUser)
            <span class="whoami">Sesión de <strong>{{ $currentUser->name }}</strong></span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-sm">Salir</button>
            </form>
        @endif
    </div>
</header>

@if (session('success'))
    <div class="alert a-ok">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="alert a-err">
        <strong>Revisa el formulario:</strong>
        <ul style="margin:8px 0 0;padding-left:20px">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@yield('content')

</div>
</body>
</html>