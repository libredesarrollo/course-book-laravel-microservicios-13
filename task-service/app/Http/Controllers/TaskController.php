<?php

namespace App\Http\Controllers;

use App\Exceptions\UserServiceErrorException;
use App\Exceptions\UserServiceUnavailableException;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use App\Services\UserServiceClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * CRUD de tareas para la interfaz web.
 *
 * Usa exactamente el mismo UserServiceClient que el Api\TaskController:
 * la diferencia entre la UI y la API es solo cómo se renderiza el resultado,
 * no de dónde viene el dato.
 */
class TaskController extends Controller
{
    public function index(): View
    {
        return view('tasks.index', [
            'tasks' => Task::query()->latest()->paginate(10),
        ]);
    }

    public function create(UserServiceClient $userService): View
    {
        return view('tasks.create', [
            'task' => new Task(['status' => Task::STATUS_PENDING]),
            ...$this->userOptions($userService),
        ]);
    }

    public function store(StoreTaskRequest $request, UserServiceClient $userService): RedirectResponse
    {
        $validated = $request->validated();

        // El selector ya viene del user-service, pero se revalida: entre que se
        // cargó el formulario y se envió, el usuario pudo haber sido borrado.
        if ($userService->find($validated['user_id']) === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Ese usuario no existe en el user-service.',
            ]);
        }

        Task::query()->create([
            ...$validated,
            'status' => $validated['status'] ?? Task::STATUS_PENDING,
        ]);

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Tarea creada correctamente.');
    }

    public function show(Task $task, UserServiceClient $userService): View
    {
        return view('tasks.show', [
            'task' => $task,
            // Degradación elegante: si el user-service está caído, la tarea se
            // muestra igual, solo sin el nombre del propietario.
            'owner' => rescue(fn () => $userService->find($task->user_id), report: false),
        ]);
    }

    public function edit(Task $task, UserServiceClient $userService): View
    {
        return view('tasks.edit', [
            'task' => $task,
            ...$this->userOptions($userService),
        ]);
    }

    public function update(UpdateTaskRequest $request, Task $task, UserServiceClient $userService): RedirectResponse
    {
        $validated = $request->validated();

        if (isset($validated['user_id']) && $validated['user_id'] !== $task->user_id) {
            if ($userService->find($validated['user_id']) === null) {
                throw ValidationException::withMessages([
                    'user_id' => 'Ese usuario no existe en el user-service.',
                ]);
            }
        }

        $task->update($validated);

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Tarea actualizada correctamente.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $task->delete();

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Tarea eliminada.');
    }

    /**
     * Descarga la lista de usuarios al user-service para el <select> del formulario.
     *
     * @return array{users: array<int, array{id: int, name: string, email: string}>, usersServiceDown: bool}
     */
    private function userOptions(UserServiceClient $userService): array
    {
        try {
            return ['users' => $userService->all(), 'usersServiceDown' => false];
        } catch (UserServiceUnavailableException|UserServiceErrorException) {
            return ['users' => [], 'usersServiceDown' => true];
        }
    }
}
