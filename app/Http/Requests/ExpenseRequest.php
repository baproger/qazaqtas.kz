<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // Чек при создании НЕ обязателен: если менеджер его не приложил,
        // бухгалтер прикрепит при подтверждении (без чека подтвердить нельзя).
        $fileRule = ['nullable'];

        return [
            'expenseable_type' => ['nullable', Rule::in(['deal', 'project'])],
            'expenseable_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'exists:expense_categories,id'],
            // Расход по материалам: позиция склада + количество; сумма считается
            // на сервере (количество × закупочная цена), вручную — только если
            // у материала цена не заведена.
            'material_id' => ['nullable', 'exists:materials,id'],
            'qty' => ['required_with:material_id', 'nullable', 'numeric', 'min:0.01'],
            'amount' => ['required_without:material_id', 'nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            // direct — прочий (чек), delivery — доставка, purchase — закуп
            'type' => ['nullable', Rule::in(['direct', 'indirect', 'delivery', 'purchase', 'assembly'])],
            'status' => ['nullable', Rule::in(['draft', 'pending', 'confirmed'])],
            'payment_method' => ['nullable', Rule::in(['cash', 'bank'])],
            'file' => [...$fileRule, 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Прикрепите чек (фото или PDF).',
            'file.mimes' => 'Чек должен быть изображением или PDF.',
            'file.max' => 'Размер чека не должен превышать 10 МБ.',
        ];
    }
}
