<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Воронка QAZAQ TAS: Заявка → Цех → Товар готов → Предоплата → Отправка →
 * Оплата успешно → Закрытый. Этапов «Акт» и «ЭСФ» в ней НЕТ.
 *
 * Раньше в такой воронке спец-этапы подставлялись по позиции: «Актом»
 * становилась «Оплата успешно» (второй с конца), и это ломало сразу две вещи —
 * сделку нельзя было закрыть успешной, а цех, завершив заказ, отправлял её
 * прямиком в «Оплата успешно» с деньгами, ЗП и аналитикой.
 */
class WorkshopGateStageTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->companyId = (int) Company::where('code', 'QT')->value('id');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('admin');
        $this->manager->companies()->attach($this->companyId);

        $funnel = [
            ['Заявка', null],
            ['Цех', 'shop_gate'],
            ['Товар готов', null],
            ['Предоплата', null],
            ['Отправка', null],
            ['Оплата успешно', 'payment_won'],
            ['Закрытый(База)', null],
        ];
        foreach ($funnel as $i => [$name, $type]) {
            DealStage::create([
                'company_id' => $this->companyId,
                'name' => $name, 'order' => $i + 1, 'color' => '#888',
                'stage_type' => $type, 'is_won' => $type === 'payment_won', 'is_active' => true,
            ]);
        }

        ProjectStage::create([
            'company_id' => $this->companyId, 'name' => 'Формовка',
            'order' => 1, 'color' => '#888', 'is_active' => true, 'workshop' => 'Шымкент',
        ]);
    }

    private function stage(string $name): DealStage
    {
        return DealStage::where('name', $name)->firstOrFail();
    }

    private function deal(string $stageName): Deal
    {
        return Deal::create([
            'company_id' => $this->companyId,
            'number' => 'QT-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'Асхат',
            'budget' => 1068000, 'status' => 'active',
            'deal_stage_id' => $this->stage($stageName)->id,
            'responsible_user_id' => $this->manager->id,
        ]);
    }

    /** С этапа «Цех» руками дальше не перевести — только кнопкой «В цех». */
    public function test_deal_cannot_skip_the_workshop_stage(): void
    {
        $deal = $this->deal('Цех');

        $this->actingAs($this->manager)
            ->patch(route('deals.stage', $deal->id), ['deal_stage_id' => $this->stage('Товар готов')->id])
            ->assertSessionHas('error');

        $this->assertSame($this->stage('Цех')->id, $deal->fresh()->deal_stage_id);

        // «Далее →» — тот же запрет: он идёт через тот же переход этапов.
        $this->actingAs($this->manager)->patch(route('deals.advance', $deal->id))
            ->assertSessionHas('error');

        $this->assertSame($this->stage('Цех')->id, $deal->fresh()->deal_stage_id);
    }

    /** Назад с этапа «Цех» вернуть можно — запрет только на движение вперёд. */
    public function test_deal_can_still_go_back_from_the_workshop_stage(): void
    {
        $deal = $this->deal('Цех');

        $this->actingAs($this->manager)
            ->patch(route('deals.stage', $deal->id), ['deal_stage_id' => $this->stage('Заявка')->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->stage('Заявка')->id, $deal->fresh()->deal_stage_id);
    }

    /** Единственный путь вперёд — «В цех»: создаётся заказ производства. */
    public function test_the_only_way_forward_is_sending_to_the_workshop(): void
    {
        $deal = $this->deal('Цех');

        $this->actingAs($this->manager)
            ->post(route('deals.toWorkshop', $deal->id), ['workshop' => 'Шымкент'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($deal->fresh()->project);
    }

    /**
     * Воронка без «Акта»: сделку можно закрыть успешной. Раньше подставной
     * «Акт» (он же «Оплата успешно») требовал, чтобы сделка уже была на этом
     * этапе, — перевести её туда было нельзя вообще.
     */
    public function test_deal_reaches_the_won_stage_without_an_act_stage(): void
    {
        $deal = $this->deal('Отправка');

        $this->actingAs($this->manager)
            ->patch(route('deals.stage', $deal->id), ['deal_stage_id' => $this->stage('Оплата успешно')->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->stage('Оплата успешно')->id, $deal->fresh()->deal_stage_id);
    }

    /**
     * Без назначенной «Логистики» цех не закрывает заказ молча в «Оплата
     * успешно», а просит назначить тип в админке.
     */
    public function test_workshop_return_without_logistics_type_explains_itself(): void
    {
        $deal = $this->deal('Цех');
        $this->actingAs($this->manager)
            ->post(route('deals.toWorkshop', $deal->id), ['workshop' => 'Шымкент'])
            ->assertSessionHasNoErrors();

        [$ok, $message] = app(ProjectService::class)->completeAndReturnDeal($deal->fresh()->project);

        $this->assertFalse($ok);
        $this->assertStringContainsString('Логистика', $message);
        $this->assertSame($this->stage('Цех')->id, $deal->fresh()->deal_stage_id, 'Сделка осталась на месте.');
    }

    /** Назначили тип «Логистика» — возврат из цеха идёт именно туда. */
    public function test_workshop_returns_the_deal_to_the_logistics_stage(): void
    {
        $this->stage('Товар готов')->update(['stage_type' => 'logistics']);

        $deal = $this->deal('Цех');
        $this->actingAs($this->manager)
            ->post(route('deals.toWorkshop', $deal->id), ['workshop' => 'Шымкент'])
            ->assertSessionHasNoErrors();

        [$ok] = app(ProjectService::class)->completeAndReturnDeal($deal->fresh()->project);

        $this->assertTrue($ok);
        $this->assertSame($this->stage('Товар готов')->id, $deal->fresh()->deal_stage_id);
        $this->assertSame('completed', Project::firstOrFail()->status);
    }
}
