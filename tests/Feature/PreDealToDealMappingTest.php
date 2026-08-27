<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PreDeal;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «В работу ✓»: что менеджер ввёл в заявке — то и стоит в полях сделки.
 *
 * Раньше переносились только заказчик и сумма, а изделие, объём и объект
 * оставались абзацем в «Описании»: менеджер вбивал их в сделку второй раз, а
 * цех читал их из текста. Подписи полей у заявки и сделки разные, поэтому
 * переносим по смыслу, а не по имени колонки.
 */
class PreDealToDealMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private int $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->company = Company::where('code', 'QT')->value('id');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach($this->company);
    }

    /** Заявка с хорошей маржой: её и переводят в сделку. */
    private function preDeal(array $extra = []): PreDeal
    {
        return PreDeal::create(array_merge(PreDeal::calculate([
            'company_id' => $this->company,
            'user_id' => $this->manager->id,
            'request_number' => '001987',
            'valid_until' => '2026-09-05',
            'bin' => '093219487100',
            'customer' => 'Акимат г. Шымкент',
            'object_address' => 'г. Шымкент, ЖК Керемет',
            'client_name' => 'Айдос',
            'client_phone' => '+77000980987',
            'product' => 'Плитка «Ромб» 190×330×60',
            'quantity' => 250,
            'unit' => 'м²',
            'unit_price' => 9000,
            'purchase_price' => 2000,
            'status' => 'new',
        ]), $extra));
    }

    /** Объект, изделие и объём встают в свои поля сделки. */
    public function test_fields_land_in_the_right_deal_fields(): void
    {
        $preDeal = $this->preDeal();

        $this->actingAs($this->manager)
            ->post(route('preDeals.confirm', $preDeal->id))
            ->assertSessionHasNoErrors();

        $deal = $preDeal->fresh()->deal;

        $this->assertSame('Акимат г. Шымкент', $deal->company_name, 'Заказчик → Компания');
        $this->assertSame('г. Шымкент, ЖК Керемет', $deal->address, 'Объект → Адрес');
        $this->assertSame('Плитка «Ромб» 190×330×60', $deal->client_name, 'Изделие → Наименование товара');
        $this->assertSame(250.0, (float) $deal->lot_number, 'Объём → Количество');
        $this->assertSame('м²', $deal->unit);
    }

    /**
     * Контакт и БИН уходят в заметку.
     *
     * Отдельных полей под них в сделке нет, а терять нельзя: по этому
     * телефону звонят из цеха, когда машина не заезжает на объект. В
     * «Наименование товара» контакт попадать не должен — там изделие.
     */
    public function test_contact_and_bin_go_to_the_note(): void
    {
        $preDeal = $this->preDeal();

        $this->actingAs($this->manager)->post(route('preDeals.confirm', $preDeal->id));
        $deal = $preDeal->fresh()->deal;

        $this->assertStringContainsString('Айдос', $deal->note);
        $this->assertStringContainsString('+77000980987', $deal->note);
        $this->assertStringContainsString('093219487100', $deal->note);
        $this->assertStringNotContainsString('Айдос', (string) $deal->client_name);
    }

    /**
     * Договора на этом шаге ещё нет — «Номер договора», «Дата договора» и
     * «Срок» пустые. Срок действия КП — не срок сделки: КП живёт неделю, и
     * сделка приезжала бы в воронку сразу просроченной.
     */
    public function test_contract_fields_stay_empty(): void
    {
        $preDeal = $this->preDeal();

        $this->actingAs($this->manager)->post(route('preDeals.confirm', $preDeal->id));
        $deal = $preDeal->fresh()->deal;

        $this->assertNull($deal->bin);
        $this->assertNull($deal->contract_date);
        $this->assertNull($deal->deadline);
        $this->assertStringContainsString('КП действует до 05.09.2026', $deal->note);
    }

    /**
     * В описании сделки нет денег.
     *
     * Описание видно в цехе — бригадир открывает карточку заказа. Закуп и
     * расчётная маржа стояли там строкой: себестоимость уезжала тому, кому
     * её видеть нельзя.
     */
    public function test_the_description_carries_no_cost_price(): void
    {
        $preDeal = $this->preDeal();

        $this->actingAs($this->manager)->post(route('preDeals.confirm', $preDeal->id));
        $deal = $preDeal->fresh()->deal;

        $this->assertStringNotContainsString('закуп', mb_strtolower((string) $deal->description));
        $this->assertStringNotContainsString('маржа', mb_strtolower((string) $deal->description));
        $this->assertStringNotContainsString('2 000', (string) $deal->description);
        $this->assertStringContainsString('Плитка «Ромб» 190×330×60', $deal->description);
    }

    /**
     * Заявка позициями: объём складывается из них, пока единица одна.
     * Разные единицы в одно число не сходятся — там поле остаётся пустым,
     * детали видно в позициях сделки.
     */
    public function test_quantity_is_summed_from_items_of_one_unit(): void
    {
        $tile = Product::create(['name' => 'Плитка «Соты»', 'unit' => 'м²', 'price' => 9000, 'is_active' => true]);
        $vase = Product::create(['name' => 'Вазон «Куб»', 'unit' => 'м²', 'price' => 12000, 'is_active' => true]);

        $preDeal = $this->preDeal(['quantity' => 0, 'unit' => null, 'contract_sum' => 3000000]);
        $preDeal->items()->createMany([
            ['product_id' => $tile->id, 'name' => $tile->name, 'unit' => 'м²', 'quantity' => 120, 'price' => 9000, 'amount' => 1080000, 'sort' => 0],
            ['product_id' => $vase->id, 'name' => $vase->name, 'unit' => 'м²', 'quantity' => 80, 'price' => 12000, 'amount' => 960000, 'sort' => 1],
        ]);

        $this->actingAs($this->manager)->post(route('preDeals.confirm', $preDeal->id));
        $deal = $preDeal->fresh()->deal;

        $this->assertSame(200.0, (float) $deal->lot_number);
        $this->assertSame('м²', $deal->unit);
        // И сами позиции доехали до сделки — вводить их второй раз не нужно.
        $this->assertCount(2, $deal->items);
    }

    /** Единицы разные — количество одним числом не пишем. */
    public function test_mixed_units_leave_the_quantity_empty(): void
    {
        $preDeal = $this->preDeal(['quantity' => 0, 'unit' => null, 'contract_sum' => 3000000]);
        $preDeal->items()->createMany([
            ['name' => 'Плитка «Соты»', 'unit' => 'м²', 'quantity' => 120, 'price' => 9000, 'amount' => 1080000, 'sort' => 0],
            ['name' => 'Урна «Конус»', 'unit' => 'штук', 'quantity' => 6, 'price' => 40000, 'amount' => 240000, 'sort' => 1],
        ]);

        $this->actingAs($this->manager)->post(route('preDeals.confirm', $preDeal->id));
        $deal = $preDeal->fresh()->deal;

        $this->assertNull($deal->lot_number);
        $this->assertNull($deal->unit);
    }
}
