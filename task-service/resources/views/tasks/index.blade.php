@extends('layouts.app')

@section('title', 'Tareas')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap">
        <div>
            <h2>Tareas</h2>
            <p class="sub" style="margin:0">
                Cada tarea guarda un <code>user_id</code> que fue validado contra el user-service por HTTP.
            </p>
        </div>
        <a class="btn btn-primary" href="{{ route('tasks.create') }}">+ Nueva tarea</a>
    </div>

    <div class="card" style="margin-top:22px">
        @if ($tasks->isEmpty())
            <div class="empty">
                <p style="margin:0 0 14px">Todavía no hay tareas.</p>
                <a class="btn btn-primary" href="{{ route('tasks.create') }}">Crear la primera</a>
            </div>
        @else
            <table>
                <thead>
                <tr>
                    <th style="width:52px">#</th>
                    <th>Título</th>
                    <th style="width:92px">Usuario</th>
                    <th style="width:118px">Estado</th>
                    <th style="width:140px"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($tasks as $task)
                    <tr>
                        <td style="color:var(--muted)">{{ $task->id }}</td>
                        <td>
                            <a href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                        </td>
                        <td><code>#{{ $task->user_id }}</code></td>
                        <td>
                            <span class="badge b-{{ $task->status }}">{{ $task->status }}</span>
                        </td>
                        <td class="actions">
                            <div class="row-actions">
                                <a class="btn btn-sm" href="{{ route('tasks.edit', $task) }}">Editar</a>
                                <form method="POST" action="{{ route('tasks.destroy', $task) }}"
                                      onsubmit="return confirm('¿Eliminar esta tarea?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if ($tasks->hasPages())
                <div class="pager">
                    @if ($tasks->onFirstPage())
                        <span class="btn btn-sm" aria-disabled="true">Anterior</span>
                    @else
                        <a class="btn btn-sm" href="{{ $tasks->previousPageUrl() }}">Anterior</a>
                    @endif

                    <span>Página {{ $tasks->currentPage() }} de {{ $tasks->lastPage() }}</span>

                    @if ($tasks->hasMorePages())
                        <a class="btn btn-sm" href="{{ $tasks->nextPageUrl() }}">Siguiente</a>
                    @else
                        <span class="btn btn-sm" aria-disabled="true">Siguiente</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endsection