<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // «Количество» приходит из числового поля как number — колонка строковая.
        if ($this->has('lot_number') && $this->lot_number !== null) {
            $this->merge(['lot_number' => (string) $this->lot_number]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 'name' из формы убран: название сделки = название компании
            // (контроллер зеркалит name ← company_name, колонка живёт для легаси).
            'client_name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            // В UI поле называется «Номер договора» (историческое имя колонки — bin).
            'bin' => ['nullable', 'string', 'max:100'],
            'contract_date' => ['nullable', 'date'],
            // В UI — «Количество» (историческое имя колонки — lot_number) + ед. изм.
            'lot_number' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Deal::UNITS)],
            'source' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Deal::SOURCES)],
            'client_id' => ['nullable', 'exists:clients,id'],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'budget' => ['required', 'numeric', 'min:0'],
            // Доля партнёра — только %; сумма считается от суммы договора.
            'partner_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            // deal_stage_id и status намеренно НЕ принимаются здесь: смена этапа/статуса идёт
            // только через updateStage → StageTransitionService (гейты задач/порядка этапов).
            // Иначе update обходил бы порядок этапов (Акт → ЭСФ → Оплата) и права бухгалтера.
        ];
    }
}
