@extends('layouts.app')

@section('title', 'Editar tarea')

@section('content')
    <h2>Editar tarea #{{ $task->id }}</h2>
    <p class="sub">El propietario no se puede cambiar: una tarea sigue siendo de quien la creó.</p>

    <div class="card">
        <form method="POST" action="{{ route('tasks.update', $task) }}">
            @csrf
            @method('PUT')
            @include('tasks._form', ['submitLabel' => 'Guardar cambios'])
        </form>
    </div>
@endsection