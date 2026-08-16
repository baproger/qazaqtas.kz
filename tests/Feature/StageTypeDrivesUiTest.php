<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кнопка «В цех» стоит на этапе, которому назначен СИСТЕМНЫЙ ТИП
 * «Закуп / отправка в цех», а не на этапе с подходящим названием.
 *
 * Раньше этап искали по слову «закуп», а если такого не было — брали «третий
 * с конца». В воронке QAZAQ TAS третьим с конца оказалась «Отправка», и
 * зелёная кнопка стояла там, куда владелец её не ставил и убрать через
 * админку не мог.
 */
class StageTypeDrivesUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->companies()->attach(Company::where('code', 'QT')->value('id'));

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    /** Страница сделок отдаёт системный тип каждого этапа. */
    public function test_stage_type_reaches_the_deals_page(): void
    {
        $gate = DealStage::where('stage_type', 'shop_gate')->firstOrFail();

        $this->actingAs($this->admin())->get(route('deals.index'))
            ->assertInertia(fn ($page) => $page
                ->where('stages', fn ($stages) => collect($stages)
                    ->firstWhere('id', $gate->id)['stage_type'] === 'shop_gate'));
    }

    /** И карточка сделки — тоже: кнопка на ней решается тем же признаком. */
    public function test_stage_type_reaches_the_deal_card(): void
    {
        $gate = DealStage::where('stage_type', 'shop_gate')->firstOrFail();
        $plain = DealStage::whereNull('stage_type')->orderByDesc('order')->firstOrFail();

        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-1', 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => $plain->id,
            'responsible_user_id' => $this->admin()->id,
        ]);

        $this->actingAs($this->admin())->get(route('deals.show', $deal->id))
            ->assertInertia(fn ($page) => $page
                ->where('stages', function ($stages) use ($gate, $plain) {
                    $rows = collect($stages);

                    // У этапа-ворот тип есть, у обычного («Отправка») — пусто,
                    // поэтому кнопка «В цех» на нём не появится.
                    return $rows->firstWhere('id', $gate->id)['stage_type'] === 'shop_gate'
                        && $rows->firstWhere('id', $plain->id)['stage_type'] === null;
                }));
    }

    /** Тип уникален в воронке — двух «ворот в цех» быть не может. */
    public function test_only_one_stage_holds_the_type(): void
    {
        $this->assertSame(1, DealStage::where('stage_type', 'shop_gate')->count());
    }
}
