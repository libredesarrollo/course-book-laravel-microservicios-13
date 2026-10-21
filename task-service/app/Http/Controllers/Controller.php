<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Demo\Microservices\UserServiceClient\AuthenticatedUser;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class Controller
{
    /**
     * Identidad resuelta por el middleware AuthenticateWithUserService.
     */
    protected function user(): AuthenticatedUser
    {
        return AuthenticatedUser::fromRequest(request());
    }

    /**
     * Resuelve una tarea y comprueba que sea del usuario en sesión.
     *
     * El modelo se busca aquí y no con route model binding a propósito. El
     * binding se resuelve ANTES del middleware de autenticación, así que un
     * usuario anónimo vería 404 para "esta tarea no existe" y 403 para
     * "existe pero no es tuya": dos respuestas distintas para dos datos que
     * no debería conocer. Resolviéndolo después de autenticar, un intruso solo
     * ve siempre 401.
     *
     * @throws NotFoundHttpException
     * @throws HttpException
     */
    protected function findOwnedTask(int $taskId): Task
    {
        $task = Task::query()->findOrFail($taskId);

        abort_unless($this->owns($task->user_id), 403, 'Esta tarea no es tuya.');

        return $task;
    }

    /**
     * ¿El usuario en sesión es dueño de este recurso?
     *
     * El task-service no puede preguntar al user-service por la propiedad de
     * una tarea, porque esa tarea no existe para él: solo conoce el
     * user_id guardado en su propia base de datos.
     */
    protected function owns(int $userId): bool
    {
        return $this->user()->id === $userId;
    }
}
