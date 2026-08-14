<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сделки по филиалам: Шымкент, Алматы, Тараз.
 */
class DealBranchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private int $counter = 0;

    private function deal(string $name, ?string $branch): Deal
    {
        // Номер уникален: в тестах бывают одноимённые сделки в разных филиалах.
        return Deal::create([
            'number' => 'QT-'.(++$this->counter),
            'name' => $name,
            'company_name' => $name,
            'branch' => $branch,
            'budget' => 100000,
            'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $this->admin->id,
        ]);
    }

    public function test_branch_tab_shows_only_its_own_deals(): void
    {
        $this->deal('Шымкентская', 'Шымкент');
        $this->deal('Алматинская', 'Алматы');

        $this->actingAs($this->admin)->get(route('deals.index', ['branch' => 'Шымкент']))
            ->assertInertia(fn ($page) => $page
                ->has('deals', 1)
                ->where('deals.0.company_name', 'Шымкентская'));
    }

    /**
     * Сделка без филиала не должна пропадать: у неё своя вкладка, иначе
     * она не попадала бы ни в одну и терялась из виду.
     */
    public function test_deals_without_a_branch_have_their_own_tab(): void
    {
        $this->deal('Без площадки', null);
        $this->deal('Тараз', 'Тараз');

        $this->actingAs($this->admin)->get(route('deals.index', ['branch' => '__none']))
            ->assertInertia(fn ($page) => $page
                ->has('deals', 1)
                ->where('deals.0.company_name', 'Без площадки'));
    }

    public function test_counts_cover_every_branch_and_ignore_the_chosen_tab(): void
    {
        $this->deal('Ш1', 'Шымкент');
        $this->deal('Ш2', 'Шымкент');
        $this->deal('А1', 'Алматы');
        $this->deal('Ничей', null);

        // Вкладка выбрана, но счётчики остальных не обнуляются — иначе по ним
        // нельзя было бы понять, куда переключаться.
        $this->actingAs($this->admin)->get(route('deals.index', ['branch' => 'Шымкент']))
            ->assertInertia(fn ($page) => $page
                ->where('branchCounts.Шымкент', 2)
                ->where('branchCounts.Алматы', 1)
                ->where('branchCounts.Тараз', 0)
                ->where('branchCounts.__none', 1));
    }

    public function test_branch_filter_works_together_with_search(): void
    {
        $this->deal('Керемет', 'Шымкент');
        $this->deal('Керемет', 'Алматы');

        $this->actingAs($this->admin)->get(route('deals.index', ['branch' => 'Алматы', 'search' => 'Керемет']))
            ->assertInertia(fn ($page) => $page->has('deals', 1));
    }

    public function test_branch_can_be_changed_from_the_deal_card(): void
    {
        $deal = $this->deal('Смена площадки', 'Шымкент');

        $this->actingAs($this->admin)->get(route('deals.show', $deal->id))
            ->assertInertia(fn ($page) => $page
                ->where('deal.branch', 'Шымкент')
                ->has('branches', 3));

        $this->actingAs($this->admin)->put(route('deals.update', $deal->id), [
            'client_name' => 'Смена площадки',
            'company_name' => 'Смена площадки',
            'address' => 'ул. Промышленная, 1',
            'budget' => 100000,
            'branch' => 'Тараз',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Тараз', $deal->fresh()->branch);
    }

    public function test_unknown_branch_is_rejected(): void
    {
        $deal = $this->deal('Проверка', 'Шымкент');

        $this->actingAs($this->admin)->put(route('deals.update', $deal->id), [
            'client_name' => 'Проверка',
            'company_name' => 'Проверка',
            'address' => 'ул. Промышленная, 1',
            'budget' => 100000,
            'branch' => 'Караганда',
        ])->assertSessionHasErrors('branch');

        $this->assertSame('Шымкент', $deal->fresh()->branch);
    }
}
