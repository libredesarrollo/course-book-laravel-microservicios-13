@extends('layouts.app')

@section('title', 'Tarea #'.$task->id)

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap">
        <h2 style="margin:0">{{ $task->title }}</h2>
        <a class="btn btn-sm" href="{{ route('tasks.index') }}">← Volver</a>
    </div>

    <div class="card" style="margin-top:20px">
        <dl class="meta">
            <dt>ID</dt>
            <dd>{{ $task->id }}</dd>

            <dt>Estado</dt>
            <dd><span class="badge b-{{ $task->status }}">{{ $task->status }}</span></dd>

            <dt>user_id</dt>
            <dd>
                @if ($owner)
                    {{ $owner['name'] }}
                    <span style="color:var(--muted)">({{ $owner['email'] }})</span>
                @else
                    <code>#{{ $task->user_id }}</code>
                @endif
            </dd>

            <dt>Creada</dt>
            <dd>{{ $task->created_at?->format('d/m/Y H:i') }}</dd>

            <dt>Actualizada</dt>
            <dd>{{ $task->updated_at?->format('d/m/Y H:i') }}</dd>
        </dl>
    </div>

    @if (! $owner)
        <div class="alert a-warn" style="margin-top:0">
            No se pudo resolver el nombre del propietario: el user-service no respondió.
            La tarea sigue intacta — el task-service no la necesita para nada más.
        </div>
    @endif

    <div class="row-actions" style="justify-content:flex-start;margin-top:20px">
        <a class="btn" href="{{ route('tasks.edit', $task) }}">Editar</a>
        <form method="POST" action="{{ route('tasks.destroy', $task) }}"
              onsubmit="return confirm('¿Eliminar esta tarea?')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Eliminar</button>
        </form>
    </div>
@endsection