<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'commentable_type' => ['required', Rule::in(['deal', 'project'])],
            'commentable_id' => ['required', 'integer'],
            'body' => ['required', 'string'],
            'parent_id' => ['nullable', 'exists:comments,id'],
        ];
    }
}
