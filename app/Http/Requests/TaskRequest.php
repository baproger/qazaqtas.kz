<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'taskable_type' => ['nullable', Rule::in(['deal', 'project', 'user'])],
            'taskable_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'status' => ['nullable', Rule::in(['new', 'in_progress', 'review', 'done'])],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'parent_task_id' => ['nullable', 'exists:tasks,id'],
        ];
    }
}
