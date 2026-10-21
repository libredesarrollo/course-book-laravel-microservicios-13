<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * CRUD de tareas para la interfaz web.
 *
 * Solo ve las tareas del usuario que inició sesión: el índice filtra por
 * user_id y findOwnedTask() comprueba la propiedad en cada acción individual.
 */
class TaskController extends Controller
{
    public function index(): View
    {
        return view('tasks.index', [
            'tasks' => Task::query()
                ->where('user_id', $this->user()->id)
                ->latest()
                ->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('tasks.create', [
            'task' => new Task(['status' => Task::STATUS_PENDING]),
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Task::query()->create([
            'title' => $validated['title'],
            'user_id' => $this->user()->id,
            'status' => $validated['status'] ?? Task::STATUS_PENDING,
        ]);

        Log::info('El usuario creó una tarea');

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Tarea creada correctamente.');
    }

    public function show(int $task): View
    {
        return view('tasks.show', ['task' => $this->findOwnedTask($task)]);
    }

    public function edit(int $task): View
    {
        return view('tasks.edit', ['task' => $this->findOwnedTask($task)]);
    }

    public function update(UpdateTaskRequest $request, int $task): RedirectResponse
    {
        Log::info('El usuario actualizó una tarea');

        $this->findOwnedTask($task)->update($request->validated());

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Tarea actualizada correctamente.');
    }

    public function destroy(int $task): RedirectResponse
    {
        $this->findOwnedTask($task)->delete();

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Tarea eliminada.');
    }
}
