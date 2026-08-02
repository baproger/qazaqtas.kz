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

/** Предварительные сделки: расчёт как в Excel, порог маржи 15%, персонализация. */
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

    // «Выиграл ✓»: доставка и сборка лота автоматически становятся расходами
    // сделки (🚚/🔧, confirmed, БЕЗ нал/банк — кассу не трогают); маржа лота
    // учитывает сборку; откат ↩ удаляет авто-расходы и проходит.
    public function test_confirm_creates_delivery_and_assembly_expenses(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Шкаф', 'customer' => 'Школа', 'contract_sum' => 1600000,
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

        // Откат ↩: авто-расходы лота не блокируют и удаляются вместе со сделкой.
        $this->actingAs($mgr)->post(route('preDeals.revert', $lot->id))->assertSessionHas('success');
        $this->assertSame('new', $lot->fresh()->status);
        $this->assertSame(0, \App\Models\Expense::count());
    }

    // Откат случайного «Выиграл ✓»: сделка удаляется, лот снова «В работе»;
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

        // Повторное «Выиграл» → сделка с движением (счёт) — откат запрещён.
        $this->actingAs($mgr)->post(route('preDeals.confirm', $lot->id));
        $deal2 = $lot->fresh()->deal;
        \App\Models\Invoice::create(['number' => 'I-'.uniqid(), 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal2->id, 'amount' => 100, 'status' => 'sent']);
        $this->actingAs($mgr)->post(route('preDeals.revert', $lot->id))->assertSessionHas('error');
        $this->assertSame('confirmed', $lot->fresh()->status);
    }

    // Фильтр «месяц»: показываются только лоты, ВНЕСЁННЫЕ в выбранном месяце.
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

    // Сегодня заканчивается тендер → уведомление менеджеру лота (только new-лоты).
    public function test_tender_deadline_today_notifies_manager(): void
    {
        $mgr = $this->user('manager');
        $other = $this->user('manager');
        PreDeal::create(PreDeal::calculate(['product' => 'A', 'contract_sum' => 100, 'tender_deadline' => now()->toDateString()]) + ['user_id' => $mgr->id]);
        PreDeal::create(PreDeal::calculate(['product' => 'B', 'contract_sum' => 100, 'tender_deadline' => now()->addDay()->toDateString()]) + ['user_id' => $other->id]);
        PreDeal::create(PreDeal::calculate(['product' => 'C', 'contract_sum' => 100, 'tender_deadline' => now()->toDateString()]) + ['user_id' => $other->id, 'status' => 'confirmed']);

        $this->artisan('pre-deals:notify-tender-deadline')->assertSuccessful();

        $this->assertSame(1, $mgr->notifications()->count());
        $this->assertSame('tender_deadline', $mgr->notifications()->first()->data['type']);
        $this->assertSame(0, $other->notifications()->count()); // завтра/выигран — не беспокоим
    }

    // Кнопка «Проверить №» до заполнения формы: занят/свободен + кто внёс;
    // при правке свой номер не считается занятым (ignore).
    public function test_check_lot_endpoint(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Стол', 'contract_sum' => 100000, 'lot_number' => 'LOT-1',
        ]);
        $lot = PreDeal::firstOrFail();

        $this->actingAs($mgr)->getJson(route('preDeals.checkLot', ['lot_number' => 'LOT-1']))
            ->assertOk()->assertJson(['exists' => true, 'manager' => $mgr->name]);
        $this->actingAs($mgr)->getJson(route('preDeals.checkLot', ['lot_number' => 'LOT-9']))
            ->assertOk()->assertJson(['exists' => false]);
        $this->actingAs($mgr)->getJson(route('preDeals.checkLot', ['lot_number' => 'LOT-1', 'ignore' => $lot->id]))
            ->assertOk()->assertJson(['exists' => false]);
    }

    // Дубль № лота запрещён: второй ввод того же лота — ошибка валидации;
    // правка самого лота без смены номера ложно не срабатывает.
    public function test_duplicate_lot_number_rejected(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Стол', 'contract_sum' => 100000, 'lot_number' => 'LOT-777',
        ])->assertSessionHasNoErrors();

        $this->actingAs($mgr)->post(route('preDeals.store'), [
            'product' => 'Стул', 'contract_sum' => 50000, 'lot_number' => 'LOT-777',
        ])->assertSessionHasErrors('lot_number');
        $this->assertSame(1, PreDeal::count());

        // Правка своего лота с тем же номером — проходит (ignore self).
        $lot = PreDeal::firstOrFail();
        $this->actingAs($mgr)->put(route('preDeals.update', $lot), [
            'product' => 'Стол обновлённый', 'contract_sum' => 120000, 'lot_number' => 'LOT-777',
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
            'product' => 'Шкаф', 'contract_sum' => 3225306, 'purchase_price' => 1897552.14,
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

        // Менеджер ставит галочку на СВОЁМ лоте.
        PreDeal::create(PreDeal::calculate(['product' => 'A', 'contract_sum' => 100]) + ['user_id' => $mgr->id]);
        $lot = PreDeal::firstOrFail();
        $this->actingAs($mgr)->post(route('preDeals.check', [$lot->id, $item->id]))->assertRedirect();
        $this->assertTrue((bool) ($lot->fresh()->checks[(string) $item->id] ?? false));
    }
}
