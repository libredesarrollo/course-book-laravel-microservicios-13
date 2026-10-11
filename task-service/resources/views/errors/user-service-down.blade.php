@extends('layouts.app')

@section('title', 'user-service no disponible')

@section('content')
    <div class="alert a-err" style="font-size:.95rem">
        <strong>{{ $status ?? 503 }} — user-service no disponible.</strong>
        <p style="margin:8px 0 0">{{ $message }}</p>
    </div>

    <div class="card">
        <p style="margin:0 0 14px">
            El task-service guarda sus tareas sin problema, pero <strong>no puede validar
            usuarios</strong> sin su dependencia. Esa es la caída de una dependencia en un
            sistema distribuido: este servicio está perfecto, el otro no lo está.
        </p>
        <p style="margin:0;color:var(--muted)">
            Para recuperarlo, levanta el otro servicio en otra terminal:
        </p>
        <pre style="margin:12px 0 0"><code>cd user-service &amp;&amp; php artisan serve --port=8000</code></pre>
    </div>

    <a class="btn" href="{{ route('tasks.index') }}">← Volver a las tareas</a>
@endsection