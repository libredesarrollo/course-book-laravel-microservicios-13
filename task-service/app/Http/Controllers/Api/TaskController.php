<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use App\Services\UserServiceClient;
use Illuminate\Http\JsonResponse;

class TaskController extends Controller
{
    /**
     * Display a paginated listing of the tasks.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Task::query()->latest()->paginate(15)
        );
    }

    /**
     * Store a newly created task.
     *
     * El task-service es dueño de sus datos, pero NO conoce la tabla users
     * (vive en otra base de datos, en otro servicio). Antes de guardar,
     * valida el user_id consultando al user-service por HTTP.
     */
    public function store(StoreTaskRequest $request, UserServiceClient $userService): JsonResponse
    {
        $validated = $request->validated();

        // null = el user-service respondió 404, el usuario no existe.
        if ($userService->find($validated['user_id']) === null) {
            return response()->json(['error' => 'El usuario no existe en el sistema'], 422);
        }

        $task = Task::query()->create([
            ...$validated,
            'status' => $validated['status'] ?? Task::STATUS_PENDING,
        ]);

        return response()->json($task, 201);
    }

    /**
     * Display the specified task.
     */
    public function show(Task $task): JsonResponse
    {
        return response()->json($task);
    }

    /**
     * Update the specified task.
     */
    public function update(UpdateTaskRequest $request, Task $task, UserServiceClient $userService): JsonResponse
    {
        $validated = $request->validated();

        // Solo se vuelve a consultar al user-service si el user_id cambió.
        if (isset($validated['user_id']) && $validated['user_id'] !== $task->user_id) {
            if ($userService->find($validated['user_id']) === null) {
                return response()->json(['error' => 'El usuario no existe en el sistema'], 422);
            }
        }

        $task->update($validated);

        return response()->json($task->fresh());
    }

    /**
     * Remove the specified task.
     */
    public function destroy(Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(status: 204);
    }
}
