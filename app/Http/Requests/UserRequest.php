<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$userId ? 'nullable' : 'required', 'confirmed', Password::min(6)],
            'department_id' => ['nullable', 'exists:departments,id'],
            // Доступ к цехам (Шымкент/Алматы/Тараз); пусто = все цеха.
            'workshops' => ['nullable', 'array'],
            'workshops.*' => ['string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'hired_at' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            // Личный % бонуса менеджера: считается от чистого остатка сделки.
            'bonus_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Трудовой договор — необязательный файл (PDF/фото/скан).
            'contract' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:10240'],
            'role' => ['required', Rule::exists('roles', 'name')],
            'is_active' => ['boolean'],
        ];
    }
}
