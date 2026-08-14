<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DealStage;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Порядок этапов воронки.
 *
 * Воронка на странице — это этапы фирмы ПЛЮС общие (company_id = null).
 * Раньше нумерация и перестановка считались строго по company_id, поэтому
 * в списке появлялись два этапа с order = 1, а стрелка вверх упиралась в
 * «этап уже первый», хотя выше него на экране были другие.
 */
class StageOrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /** Воронка так и выглядит на проде: общие этапы плюс собственный этап фирмы. */
    private function mixedFunnel(): int
    {
        $this->seed(StageSeeder::class);
        $companyId = (int) Company::orderBy('id')->value('id');

        DealStage::create([
            'company_id' => $companyId,
            'name' => 'Закрытый (База)',
            'order' => 1,
            'is_active' => true,
            'type' => 'sale',
        ]);

        return $companyId;
    }

    public function test_opening_the_page_renumbers_the_whole_funnel(): void
    {
        $companyId = $this->mixedFunnel();

        // До открытия страницы номер 1 занят дважды.
        $this->assertSame(2, DealStage::where('order', 1)->count());

        $this->actingAs($this->admin())->get(route('stages.index', ['company' => $companyId]))->assertOk();

        $orders = DealStage::orderBy('order')->pluck('order')->all();

        $this->assertSame(range(1, count($orders)), $orders);
    }

    public function test_reorder_saves_the_list_as_sent(): void
    {
        $companyId = $this->mixedFunnel();
        $this->actingAs($this->admin())->get(route('stages.index', ['company' => $companyId]));

        $ids = DealStage::orderBy('order')->pluck('id')->all();
        $moved = [array_pop($ids), ...$ids];

        $this->actingAs($this->admin())
            ->patch(route('stages.reorder', 'deal'), ['ids' => $moved, 'company' => $companyId])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($moved, DealStage::orderBy('order')->pluck('id')->all());
        $this->assertSame(range(1, count($moved)), DealStage::orderBy('order')->pluck('order')->all());
    }

    /**
     * Собственный этап фирмы поднимается выше общих. Обмен с соседом здесь и
     * ломался: соседа «своей» компании у него не было.
     */
    public function test_company_stage_moves_above_the_shared_ones(): void
    {
        $companyId = $this->mixedFunnel();
        $this->actingAs($this->admin())->get(route('stages.index', ['company' => $companyId]));

        $own = DealStage::where('company_id', $companyId)->firstOrFail();
        $ids = DealStage::orderBy('order')->pluck('id')->all();
        $ids = [$own->id, ...array_values(array_diff($ids, [$own->id]))];

        $this->actingAs($this->admin())
            ->patch(route('stages.reorder', 'deal'), ['ids' => $ids, 'company' => $companyId])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $own->fresh()->order);
    }

    public function test_new_stage_lands_at_the_end_of_the_funnel(): void
    {
        $companyId = $this->mixedFunnel();
        $this->actingAs($this->admin())->get(route('stages.index', ['company' => $companyId]));

        $last = (int) DealStage::max('order');

        $this->actingAs($this->admin())
            ->post(route('stages.store', ['company' => $companyId]), ['kind' => 'deal', 'name' => 'Аванс'])
            ->assertSessionHasNoErrors();

        $stage = DealStage::where('name', 'Аванс')->firstOrFail();

        $this->assertSame($last + 1, (int) $stage->order);
        // И номер не занят никем другим.
        $this->assertSame(1, DealStage::where('order', $stage->order)->count());
    }

    /** У каждого цеха своя нумерация: перестановка в Алматы не трогает Шымкент. */
    public function test_workshop_funnels_are_ordered_apart(): void
    {
        $this->seed(StageSeeder::class);
        $companyId = (int) Company::orderBy('id')->value('id');
        $admin = $this->admin();

        $almaty = ProjectStage::where('workshop', 'Алматы')->orderBy('order')->pluck('id')->all();
        $shymkentBefore = ProjectStage::where('workshop', 'Шымкент')->orderBy('order')->pluck('id')->all();

        $this->actingAs($admin)->patch(route('stages.reorder', 'project'), [
            'ids' => array_reverse($almaty),
            'company' => $companyId,
            'workshop' => 'Алматы',
        ])->assertSessionHasNoErrors();

        $this->assertSame(array_reverse($almaty), ProjectStage::where('workshop', 'Алматы')->orderBy('order')->pluck('id')->all());
        $this->assertSame($shymkentBefore, ProjectStage::where('workshop', 'Шымкент')->orderBy('order')->pluck('id')->all());
    }

    /** Чужой этап не должен переставляться запросом к другой воронке. */
    public function test_reorder_rejects_stages_outside_the_funnel(): void
    {
        $companyId = $this->mixedFunnel();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('stages.index', ['company' => $companyId]));

        $ids = DealStage::orderBy('order')->pluck('id')->all();
        $workshopStage = ProjectStage::firstOrFail();

        $this->actingAs($admin)->patch(route('stages.reorder', 'deal'), [
            'ids' => [$workshopStage->id, ...$ids],
            'company' => $companyId,
        ])->assertSessionHas('error');

        $this->assertSame($ids, DealStage::orderBy('order')->pluck('id')->all());
    }
}
