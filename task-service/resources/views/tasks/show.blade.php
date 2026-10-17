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

            <dt>Propietario</dt>
            <dd>
                {{ $currentUser->name }}
                <span style="color:var(--muted)">({{ $currentUser->email }})</span>
            </dd>

            <dt>Creada</dt>
            <dd>{{ $task->created_at?->format('d/m/Y H:i') }}</dd>

            <dt>Actualizada</dt>
            <dd>{{ $task->updated_at?->format('d/m/Y H:i') }}</dd>
        </dl>
    </div>

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