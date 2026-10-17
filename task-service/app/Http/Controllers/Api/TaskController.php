<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TaskController extends Controller
{
    /**
     * Display a paginated listing of the tasks owned by the caller.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            Task::query()
                ->where('user_id', $this->user()->id)
                ->latest()
                ->paginate(15)
        );
    }

    /**
     * Store a newly created task.
     *
     * Antes de la autenticación, este método validaba el user_id consultando
     * al user-service por HTTP. Ya no hace falta: el middleware
     * AuthenticateWithUserService acaba de confirmar por HTTP que ese mismo
     * usuario existe. Una segunda llamada solo volvería a preguntar lo que ya
     * sabemos, y a pagar la latencia otra vez.
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $task = Task::query()->create([
            'title' => $validated['title'],
            'user_id' => $this->user()->id,
            'status' => $validated['status'] ?? Task::STATUS_PENDING,
        ]);

        return response()->json($task, 201);
    }

    /**
     * Display the specified task.
     */
    public function show(int $task): JsonResponse
    {
        return response()->json($this->findOwnedTask($task));
    }

    /**
     * Update the specified task.
     */
    public function update(UpdateTaskRequest $request, int $task): JsonResponse
    {
        $model = $this->findOwnedTask($task);

        $model->update($request->validated());

        return response()->json($model->fresh());
    }

    /**
     * Remove the specified task.
     */
    public function destroy(int $task): Response
    {
        $this->findOwnedTask($task)->delete();

        return response()->json(status: 204);
    }
}
