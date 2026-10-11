@php use App\Models\Task; @endphp

@if ($usersServiceDown)
    <div class="alert a-warn">
        <strong>user-service no disponible.</strong>
        No se pudo cargar la lista de usuarios (petición a
        <code>{{ config('services.user_service.url') }}/api/users</code>).
        Puedes escribir el <code>user_id</code> a mano, pero al guardar se volverá a validar
        por HTTP y la operación fallará con 503 mientras el servicio siga apagado.
    </div>
@endif

<div class="field">
    <label for="title">Título</label>
    <input type="text" id="title" name="title" value="{{ old('title', $task->title) }}"
           placeholder="Ej. Revisar el reporte mensual" required autofocus>
    @error('title') <p class="err">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label for="user_id">Usuario (vive en el user-service)</label>

    @if ($usersServiceDown)
        <input type="number" id="user_id" name="user_id" min="1"
               value="{{ old('user_id', $task->user_id) }}" required>
    @else
        <select id="user_id" name="user_id" required>
            <option value="">— Selecciona un usuario —</option>
            @foreach ($users as $user)
                <option value="{{ $user['id'] }}" @selected((int) old('user_id', $task->user_id) === $user['id'])>
                    #{{ $user['id'] }} — {{ $user['name'] }} ({{ $user['email'] }})
                </option>
            @endforeach
        </select>
        <p style="font-size:.82rem;color:var(--muted);margin:7px 0 0">
            Esta lista se descargó del user-service por HTTP al abrir el formulario.
        </p>
    @endif

    @error('user_id') <p class="err">{{ $message }}</p> @enderror
</div>

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