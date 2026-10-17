@extends('layouts.app')

@section('title', 'user-service no disponible')

@section('content')
    <div class="alert a-err" style="font-size:.95rem">
        <strong>user-service no disponible.</strong>
        <p style="margin:8px 0 0">{{ $message }}</p>
    </div>

    <div class="card">
        <p style="margin:0 0 14px">
            El task-service guarda sus tareas sin problema, pero <strong>no puede autenticar
            a nadie</strong> sin su dependencia: cada request necesita que el user-service
            confirme el token. Esa es la consecuencia directa de validar la identidad por HTTP,
            y es el trade-off que un JWT stateless viene a evitar.
        </p>
        <p style="margin:0;color:var(--muted)">
            Para recuperarlo, levanta el otro servicio en otra terminal:
        </p>
        <pre style="margin:12px 0 0"><code>cd user-service &amp;&amp; php artisan serve --port=8000</code></pre>
    </div>
@endsection