@extends('layouts.app')

@section('title', 'Nueva tarea')

@section('content')
    <h2>Nueva tarea</h2>
    <p class="sub">Al guardar, el task-service valida el usuario consultando al user-service por HTTP.</p>

    <div class="card">
        <form method="POST" action="{{ route('tasks.store') }}">
            @csrf
            @include('tasks._form', ['submitLabel' => 'Crear tarea'])
        </form>
    </div>
@endsection