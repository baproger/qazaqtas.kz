<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Производство QAZAQ TAS в трёх городах: Шымкент, Алматы, Тараз. У каждого
 * своя воронка цеха, заказ уходит в выбранный город и двигается по ЕГО этапам.
 */
class WorkshopCitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_each_city_has_its_own_production_funnel(): void
    {
        // Порядок секций канбана определяет БД (у всех цехов первый этап = 1),
        // важен сам состав площадок.
        $workshops = ProjectStage::workshopsFor(null);
        sort($workshops);
        $expected = StageSeeder::WORKSHOPS;
        sort($expected);
        $this->assertSame($expected, $workshops);

        foreach (StageSeeder::WORKSHOPS as $city) {
            $funnel = ProjectStage::funnel(null, $city);
            $this->assertSame(
                ['Формовка', 'Шлифовка', 'Упаковка', 'Отправка'],
                $funnel->pluck('name')->all(),
                "Воронка цеха «{$city}» отличается от ожидаемой."
            );
            // Завершающий этап («Готово ✓» → сделка на Логистику) — один на цех.
            $this->assertSame(1, $funnel->where('is_completed', true)->count());
        }
    }

    public function test_screens_page_lists_every_city(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('screens.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'companies.0.rows',
                fn ($rows) => collect($rows)->pluck('workshop')->sort()->values()->all()
                    === collect(StageSeeder::WORKSHOPS)->sort()->values()->all()
            ));
    }

    public function test_deal_goes_to_the_chosen_city_funnel(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $deal = Deal::create([
            'number' => 'QT-C-1', 'name' => 'Плитка', 'company_name' => 'ТОО Курылыс',
            'client_name' => 'Плитка 300×300', 'budget' => 500000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal), ['workshop' => 'Тараз'])
            ->assertRedirect();

        $project = $deal->fresh()->project;
        $this->assertSame('Тараз', $project->workshop);
        $this->assertSame(
            ProjectStage::where('workshop', 'Тараз')->orderBy('order')->first()->id,
            $project->project_stage_id
        );

        // «Далее» двигает по воронке СВОЕГО города, а не соседнего.
        $this->actingAs($admin)->patch(route('projects.advance', $project))->assertRedirect();
        $this->assertSame('Шлифовка', $project->fresh()->stage->name);
        $this->assertSame('Тараз', $project->fresh()->stage->workshop);
    }
}
