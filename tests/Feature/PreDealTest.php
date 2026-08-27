<?php

namespace Tests\Feature;

use App\Models\PreDeal;
use App\Models\PreDealChecklistItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Заявки / запросы КП: расчёт как в Excel, порог маржи 15%, персонализация. */
class PreDealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    // Объём × цена за единицу = сумма КП: менеджер вводит м²/шт и цену,
    // сумма договора считается сама (и уходит в расчёт маржи).
    public function test_contract_sum_is_quantity_times_unit_price(): void
    {
        $mgr = $this->user('manager');

        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Тротуарная плитка 300×300',
            'quantity' => 250, 'unit' => 'м²', 'unit_price' => 8000,
            'contract_sum' => 1, // затирается расчётом
            'purchase_price' => 1000000,
        ])->assertRedirect();

        $request = PreDeal::firstOrFail();
        $this->assertEquals(2000000, (float) $request->contract_sum);
        // остаток = 2 000 000 − 1 000 000 − налог 3% (60 000) = 940 000 → 47%
        $this->assertEquals(940000, (float) $request->remainder);
        $this->assertEquals(47, (float) $request->margin);
    }

    // Перевод в сделку: доставка и монтаж заявки автоматически становятся
    // расходами сделки (🚚/🔧, confirmed, БЕЗ нал/банк — кассу не трогают); маржа заявки
    // учитывает сборку; откат ↩ удаляет авто-расходы и проходит.
    public function test_confirm_creates_delivery_and_assembly_expenses(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Скамья', 'customer' => 'Школа', 'contract_sum' => 1600000,
            'purchase_price' => 700000, 'delivery' => 100000, 'assembly' => 50000,
        ])->assertSessionHasNoErrors();
        $lot = PreDeal::firstOrFail();
        // 1600000 − 700000 − 100000 − 50000 − налог 48000 = 702000 → 43.88%.
        $this->assertEquals(702000.0, (float) $lot->remainder);

        $this->actingAs($mgr)->post(route('preDeals.confirm', $lot->id))->assertSessionHas('success');
        $deal = $lot->fresh()->deal;
        $exp = $deal->expenses()->get()->keyBy('type');
        $this->assertCount(2, $exp);
        $this->assertEquals(100000.0, (float) $exp['delivery']->amount);
        $this->assertEquals(50000.0, (float) $exp['assembly']->amount);
        $this->assertSame('confirmed', $exp['assembly']->status);
        $this->assertNull($exp['assembly']->payment_method); // касса/банк не тронуты

        // Откат ↩: авто-расходы заявки не блокируют и удаляются вместе со сделкой.
        $this->actingAs($mgr)->post(route('preDeals.revert', $lot->id))->assertSessionHas('success');
        $this->assertSame('new', $lot->fresh()->status);
        $this->assertSame(0, \App\Models\Expense::count());
    }

    // Откат случайного «В работу ✓»: сделка удаляется, заявка снова «В работе»;
    // при движении по сделке (счёт) откат запрещён.
    public function test_revert_confirmed_lot_back_to_predeal(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Парта', 'customer' => 'Школа', 'contract_sum' => 1600000, 'purchase_price' => 700000,
        ]);
        $lot = PreDeal::firstOrFail();
        $this->actingAs($mgr)->post(route('preDeals.confirm', $lot->id))->assertSessionHas('success');
        $deal = $lot->fresh()->deal;

        $this->actingAs($mgr)->post(route('preDeals.revert', $lot->id))->assertSessionHas('success');
        $lot->refresh();
        $this->assertSame('new', $lot->status);
        $this->assertNull($lot->deal_id);
        $this->assertNotNull($deal->fresh()?->deleted_at ?? 'deleted'); // сделка удалена (soft)
        $this->assertSame(0, \App\Models\Deal::count());

        // Повторное «В работу» → сделка с движением (счёт) — откат запрещён.
        $this->actingAs($mgr)->post(route('preDeals.confirm', $lot->id));
        $deal2 = $lot->fresh()->deal;
        \App\Models\Invoice::create(['number' => 'I-'.uniqid(), 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal2->id, 'amount' => 100, 'status' => 'sent']);
        $this->actingAs($mgr)->post(route('preDeals.revert', $lot->id))->assertSessionHas('error');
        $this->assertSame('confirmed', $lot->fresh()->status);
    }

    // Фильтр «месяц»: показываются только заявки, ВНЕСЁННЫЕ в выбранном месяце.
    public function test_month_filter_scopes_lots_by_created_date(): void
    {
        $mgr = $this->user('manager');
        PreDeal::create(PreDeal::calculate(['product' => 'Свежий', 'contract_sum' => 100]) + ['user_id' => $mgr->id]);
        $old = PreDeal::create(PreDeal::calculate(['product' => 'Старый', 'contract_sum' => 100]) + ['user_id' => $mgr->id]);
        $past = now()->subMonthNoOverflow();
        $old->timestamps = false;
        $old->forceFill(['created_at' => $past])->save();

        $this->actingAs($mgr)->get(route('preDeals.index', ['month' => $past->format('Y-m')]))
            ->assertInertia(fn ($p) => $p->component('PreDeals/Index')
                ->where('preDeals', fn ($lots) => collect($lots)->pluck('product')->all() === ['Старый']));
    }

    // Сегодня заканчивается срок КП → уведомление менеджеру заявки (только new-заявки).
    public function test_quote_deadline_today_notifies_manager(): void
    {
        $mgr = $this->user('manager');
        $other = $this->user('manager');
        PreDeal::create(PreDeal::calculate(['product' => 'A', 'contract_sum' => 100, 'valid_until' => now()->toDateString()]) + ['user_id' => $mgr->id]);
        PreDeal::create(PreDeal::calculate(['product' => 'B', 'contract_sum' => 100, 'valid_until' => now()->addDay()->toDateString()]) + ['user_id' => $other->id]);
        PreDeal::create(PreDeal::calculate(['product' => 'C', 'contract_sum' => 100, 'valid_until' => now()->toDateString()]) + ['user_id' => $other->id, 'status' => 'confirmed']);

        $this->artisan('pre-deals:notify-quote-deadline')->assertSuccessful();

        $this->assertSame(1, $mgr->notifications()->count());
        $this->assertSame('quote_deadline', $mgr->notifications()->first()->data['type']);
        $this->assertSame(0, $other->notifications()->count()); // завтра/уже в работе — не беспокоим
    }

    // Кнопка «Проверить №» до заполнения формы: занят/свободен + кто внёс;
    // при правке свой номер не считается занятым (ignore).
    public function test_check_lot_endpoint(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Вазон Астана', 'contract_sum' => 100000, 'request_number' => 'ZAY-1',
        ]);
        $lot = PreDeal::firstOrFail();

        $this->actingAs($mgr)->getJson(route('preDeals.checkNumber', ['request_number' => 'ZAY-1']))
            ->assertOk()->assertJson(['exists' => true, 'manager' => $mgr->name]);
        $this->actingAs($mgr)->getJson(route('preDeals.checkNumber', ['request_number' => 'ZAY-9']))
            ->assertOk()->assertJson(['exists' => false]);
        $this->actingAs($mgr)->getJson(route('preDeals.checkNumber', ['request_number' => 'ZAY-1', 'ignore' => $lot->id]))
            ->assertOk()->assertJson(['exists' => false]);
    }

    // Дубль № заявки запрещён: второй ввод того же заявки — ошибка валидации;
    // правка самого заявки без смены номера ложно не срабатывает.
    public function test_duplicate_request_number_rejected(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Вазон Астана', 'contract_sum' => 100000, 'request_number' => 'ZAY-777',
        ])->assertSessionHasNoErrors();

        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Скамья парковая', 'contract_sum' => 50000, 'request_number' => 'ZAY-777',
        ])->assertSessionHasErrors('request_number');
        $this->assertSame(1, PreDeal::count());

        // Правка своего заявки с тем же номером — проходит (ignore self).
        $lot = PreDeal::firstOrFail();
        $this->actingAs($mgr)->put(route('preDeals.update', $lot), [
            'product' => 'Вазон Астана (правка)', 'contract_sum' => 120000, 'request_number' => 'ZAY-777',
        ])->assertSessionHasNoErrors();
    }

    public function test_margin_calculated_like_excel(): void
    {
        // Строка из Excel: 633333 − закуп 270000 − партнёр 10% (63333.30)
        // − доставка 100000 − налог 3% (18999.99) = 180999.71 → маржа 28.58%.
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Стеллаж', 'contract_sum' => 633333, 'purchase_price' => 270000,
            'partner_pct' => 10, 'delivery' => 100000, 'commission' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $p = PreDeal::firstOrFail();
        $this->assertEquals(63333.30, (float) $p->partner_sum);
        $this->assertEquals(18999.99, (float) $p->tax);
        $this->assertEquals(180999.71, (float) $p->remainder);
        $this->assertEquals(28.58, (float) $p->margin);
    }

    public function test_low_margin_rejected_high_margin_confirmed(): void
    {
        $mgr = $this->user('manager');
        // Маржа 9.42% (шкаф из Excel) — подтверждение отклоняется.
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Скамья', 'contract_sum' => 3225306, 'purchase_price' => 1897552.14,
            'partner_pct' => 5, 'delivery' => 766000,
        ]);
        $low = PreDeal::firstOrFail();
        $this->assertEquals(9.42, (float) $low->margin);
        $this->actingAs($mgr)->post(route('preDeals.confirm', $low->id))->assertSessionHas('error');
        $this->assertSame('new', $low->fresh()->status);

        // Маржа 44.89% (парта) — подтверждается, создаётся сделка.
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Парта младший класс', 'customer' => 'ГУ Школа №5', 'contract_sum' => 1600000,
            'purchase_price' => 700000, 'partner_pct' => 5, 'commission' => 53760,
        ]);
        $ok = PreDeal::where('product', 'Парта младший класс')->firstOrFail();
        $this->assertEquals(44.89, (float) $ok->margin);
        $this->actingAs($mgr)->post(route('preDeals.confirm', $ok->id))->assertSessionHas('success');
        $ok->refresh();
        $this->assertSame('confirmed', $ok->status);
        $this->assertNotNull($ok->deal_id);
        $this->assertSame('ГУ Школа №5', $ok->deal->company_name);
        $this->assertEquals(1600000, (float) $ok->deal->budget);
        $this->assertSame($mgr->id, $ok->deal->responsible_user_id);
    }

    public function test_manager_sees_only_own_lots(): void
    {
        $a = $this->user('manager');
        $b = $this->user('manager');
        PreDeal::create(PreDeal::calculate(['product' => 'A', 'contract_sum' => 100]) + ['user_id' => $a->id]);
        PreDeal::create(PreDeal::calculate(['product' => 'B', 'contract_sum' => 100]) + ['user_id' => $b->id]);

        $this->actingAs($a)->get(route('preDeals.index'))
            ->assertInertia(fn (Assert $p) => $p->has('preDeals', 1)->where('preDeals.0.product', 'A'));
        // Руководство видит все + рейтинг.
        $this->actingAs($this->user('financist'))->get(route('preDeals.index'))
            ->assertInertia(fn (Assert $p) => $p->has('preDeals', 2)->has('stats'));
    }

    public function test_checklist_managed_by_admin_or_financist_only(): void
    {
        $fin = $this->user('financist');
        $mgr = $this->user('manager');

        $this->actingAs($fin)->post(route('preDealItems.store'), ['label' => 'Выставил счёт'])->assertRedirect();
        $item = PreDealChecklistItem::where('label', 'Выставил счёт')->firstOrFail();
        $this->actingAs($mgr)->post(route('preDealItems.store'), ['label' => 'X'])->assertForbidden();

        // Менеджер ставит галочку на СВОЁМ заявке.
        PreDeal::create(PreDeal::calculate(['product' => 'A', 'contract_sum' => 100]) + ['user_id' => $mgr->id]);
        $lot = PreDeal::firstOrFail();
        $this->actingAs($mgr)->post(route('preDeals.check', [$lot->id, $item->id]))->assertRedirect();
        $this->assertTrue((bool) ($lot->fresh()->checks[(string) $item->id] ?? false));
    }

    /**
     * Пустое денежное поле значит НОЛЬ, а не «неизвестно».
     *
     * Браузер шлёт пустую строку, middleware превращает её в null, правило
     * 'nullable' пропускает — и запись падала на NOT NULL: колонки заявки
     * объявлены `NOT NULL DEFAULT 0`. Правка заявки с пустой доставкой
     * отдавала 500.
     */
    public function test_empty_money_fields_become_zero(): void
    {
        $mgr = $this->user('manager');

        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Плитка «Большой формат»',
            'contract_sum' => 560000,
            'purchase_price' => 300000,
            'delivery' => null,
            'assembly' => null,
            'commission' => null,
        ])->assertSessionHasNoErrors();

        $preDeal = PreDeal::firstOrFail();
        $this->assertSame(0.0, (float) $preDeal->delivery);
        $this->assertSame(0.0, (float) $preDeal->assembly);

        // И правка тоже: раньше именно она отдавала 500.
        $this->actingAs($mgr)->put(route('preDeals.update', $preDeal->id), [
            'product' => 'Плитка «Большой формат»',
            'contract_sum' => 560000,
            'purchase_price' => 300000,
            'delivery' => null,
            'assembly' => 10000,
            'commission' => 17200,
        ])->assertSessionHasNoErrors();

        $preDeal->refresh();
        $this->assertSame(0.0, (float) $preDeal->delivery);
        $this->assertSame(10000.0, (float) $preDeal->assembly);
    }
}
