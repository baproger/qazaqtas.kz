<?php

namespace App\Http\Requests;

use App\Models\Deal;
use App\Models\Product;
use App\Services\PayrollService;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        // «Наименование товара» = что заказали. Выбрал менеджер позиции из
        // каталога — поле в форме не показывается, и присылать его нечего:
        // берём первую позицию. Без этого форма молча падала на required, а
        // менеджер видел неработающую кнопку «Создать сделку».
        if (blank($this->input('client_name'))) {
            $first = collect($this->input('items', []))
                ->first(fn ($row) => filled($row['name'] ?? null) || filled($row['product_id'] ?? null));

            if ($first) {
                $name = $first['name'] ?? null;
                if (blank($name) && filled($first['product_id'] ?? null)) {
                    $name = Product::whereKey($first['product_id'])->value('name');
                }
                if (filled($name)) {
                    $this->merge(['client_name' => $name]);
                }
            }
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
            // Заказчик и контакт — свои колонки, а не легаси bin/client_name:
            // у тех давно другое значение (номер договора и наименование товара).
            'customer_bin' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contract_date' => ['nullable', 'date'],
            // В UI — «Количество» (историческое имя колонки — lot_number) + ед. изм.
            'lot_number' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', Rule::in(Deal::UNITS)],
            // Филиал сделки: те же площадки, что и цеха производства.
            'branch' => ['nullable', Rule::in(StageSeeder::WORKSHOPS)],
            'area_m2' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            // Товар выбирается из каталога; название дублируется в client_name.
            'product_id' => ['nullable', 'exists:products,id'],
            // Тип сделки решает ставку бонуса: производство или перепродажа.
            'deal_type' => ['nullable', Rule::in([
                PayrollService::TYPE_PRODUCTION,
                PayrollService::TYPE_RESALE,
            ])],
            'source' => ['nullable', Rule::in(Deal::SOURCES)],
            'client_id' => ['nullable', 'exists:clients,id'],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            // Сумма приходит из формы, но при наличии позиций её пересчитает
            // сервер по строкам — руками её тогда не задать.
            'budget' => ['required', 'numeric', 'min:0'],
            // Позиции сделки: товар из каталога, количество и цена.
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
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
