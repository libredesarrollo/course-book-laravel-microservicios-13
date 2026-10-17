<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ni title, ni user_id, ni status se toman del payload para cambiar el
     * propietario: una tarea pertenece a quien la creó y no se traspasa.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([Task::STATUS_PENDING, Task::STATUS_COMPLETED])],
        ];
    }
}
