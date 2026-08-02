<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentGateAndThresholdTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_won_stage_allowed_with_partial_payment(): void
    {
        // Правило 18.07: полная оплата для «Оплата успешно» больше не требуется.
        $fin = $this->user('financist');
        $act = DealStage::create(['name' => 'Акт', 'type' => 'sale', 'order' => 90, 'is_active' => true, 'stage_type' => 'act', 'checklist' => []]);
        $esf = DealStage::create(['name' => 'ЭСФ', 'type' => 'sale', 'order' => 91, 'is_active' => true, 'stage_type' => 'esf', 'checklist' => []]);
        $won = DealStage::where('is_won', true)->first();

        $deal = Deal::create([
            'number' => 'D-1', 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 1000000, 'status' => 'active', 'deal_stage_id' => $esf->id,
        ]);
        // Оплаты нет вообще — раньше сервис блокировал переход.
        $this->actingAs($fin)->patch(route('deals.stage', $deal), ['deal_stage_id' => $won->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals($won->id, $deal->fresh()->deal_stage_id);
    }

    public function test_expenses_over_60_percent_notify_financist_once(): void
    {
        $admin = $this->user('admin');
        $fin = $this->user('financist');
        $deal = Deal::create([
            'number' => 'D-2', 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 100000, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        // 50% — порог не пересечён, уведомления нет.
        $this->actingAs($admin)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 50000, 'date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'confirmed',
        ])->assertRedirect();
        $this->assertEquals(0, $fin->notifications()->count());

        // +15% = 65% — порог пересечён, финансист уведомлён.
        $this->actingAs($admin)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 15000, 'date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'confirmed',
        ])->assertRedirect();
        $this->assertEquals(1, $fin->notifications()->count());
        $this->assertStringContainsString('60%', $fin->notifications()->first()->data['title']);

        // Ещё расход — порог уже пересечён, повторного спама нет.
        $this->actingAs($admin)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 5000, 'date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'confirmed',
        ])->assertRedirect();
        $this->assertEquals(1, $fin->notifications()->count());
    }
}
