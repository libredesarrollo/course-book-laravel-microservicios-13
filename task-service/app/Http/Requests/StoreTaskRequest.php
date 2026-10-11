<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'user_id' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in([Task::STATUS_PENDING, Task::STATUS_COMPLETED])],
        ];
    }
}
