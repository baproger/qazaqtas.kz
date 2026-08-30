<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Заявка партнёра. Фото — строго одно; MIME проверяется по содержимому файла
 * на сервере (image + mimetypes), расширению клиента не доверяем. Описание —
 * простой текст: HTML вычищается в контроллере (strip_tags), скриптам взяться
 * неоткуда.
 */
class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // права решает ServicePolicy в контроллере
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'category_id' => ['required', 'integer', Rule::exists('service_categories', 'id')->where('is_active', true)],
            'description' => ['required', 'string', 'min:20', 'max:3000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'contact_name' => ['required', 'string', 'max:100'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9() -]{6,20}$/'],
            'city' => ['nullable', 'string', 'max:100'],
            'photo' => [$this->route('service') ? 'nullable' : 'required', 'file', 'image',
                'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
        ];
    }
}
