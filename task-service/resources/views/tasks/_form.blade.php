@php use App\Models\Task; @endphp

<div class="field">
    <label for="title">Título</label>
    <input type="text" id="title" name="title" value="{{ old('title', $task->title) }}"
           placeholder="Ej. Revisar el reporte mensual" required autofocus>
    @error('title') <p class="err">{{ $message }}</p> @enderror
</div>

{{-- No hay selector de usuario: la tarea pertenece a quien tiene la sesión. --}}

<div class="field">
    <label for="status">Estado</label>
    <select id="status" name="status">
        @foreach ([Task::STATUS_PENDING => 'Pendiente', Task::STATUS_COMPLETED => 'Completada'] as $value => $label)
            <option value="{{ $value }}" @selected(old('status', $task->status) === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @error('status') <p class="err">{{ $message }}</p> @enderror
</div>

<div class="form-actions">
    <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
    <a class="btn" href="{{ route('tasks.index') }}">Cancelar</a>
</div>