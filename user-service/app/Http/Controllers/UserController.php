<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Display a paginated listing of the users.
     *
     * El task-service lo usa para poblar el selector de usuarios del formulario
     * de tareas: es la segunda forma de comunicación HTTP entre servicios.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            User::query()->orderBy('name')->paginate(50)
        );
    }

    /**
     * Display the specified user.
     *
     * Este endpoint es el que consume el task-service para saber si un
     * user_id es válido antes de crear una tarea.
     */
    public function show(int $id): JsonResponse
    {
        $user = User::query()->find($id);

        if (! $user) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }

        return response()->json($user, 200);
    }
}
