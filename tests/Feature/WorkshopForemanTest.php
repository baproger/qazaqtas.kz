<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealItem;
use App\Models\DealStage;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Бригадир в цехе: карточка заказа.
 *
 * Заказ в цехе ведёт бригада, и бригадир должен открыть карточку — иначе он
 * не знает, что делать, для кого и к какому сроку. В карточке видно и кто
 * ведёт заказ с обеих сторон: менеджер по продажам и бригадир. Денег в цехе
 * нет ни у кого: ни суммы заказа, ни цен позиций, ни счетов и расходов.
 */
class WorkshopForemanTest extends TestCase
{
    use RefreshDatabase;

    private User $foreman;

    private int $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->company = Company::where('code', 'QT')->value('id');

        // Пустой workshops = все цеха; ограничение по городу проверяем отдельно.
        $this->foreman = User::factory()->create(['name' => 'Асхат Бекболат']);
        $this->foreman->assignRole('foreman');
        $this->foreman->companies()->attach($this->company);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['name' => 'Бахытжан Сламбек']);
        $manager->assignRole('manager');
        $manager->companies()->attach($this->company);

        return $manager;
    }

    /** Сделка + заказ цеха по ней: то, что видит бригадир на доске. */
    private function order(array $dealExtra = [], string $workshop = 'Шымкент'): array
    {
        $deal = Deal::create(array_merge([
            'company_id' => $this->company,
            'number' => 'QT-'.uniqid(),
            'name' => 'Двор ЖК',
            'company_name' => 'ТОО «Клиент»',
            'address' => 'г. Шымкент, ул. Промышленная 1',
            'note' => 'Серый графит, не глянец',
            'budget' => 5000000,
            'status' => 'active',
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ], $dealExtra));

        DealItem::create([
            'deal_id' => $deal->id, 'name' => 'Плитка «Кирпичик» 200×100×60',
            'unit' => 'м²', 'quantity' => 210, 'price' => 8500, 'amount' => 1785000, 'sort' => 0,
        ]);

        $project = Project::create([
            'number' => 'PRJ-'.uniqid(),
            'name' => $deal->name,
            'deal_id' => $deal->id,
            'workshop' => $workshop,
            'project_stage_id' => ProjectStage::where('workshop', $workshop)->orderBy('order')->value('id'),
            'status' => 'active',
            'budget' => 5000000,
        ]);

        return [$deal, $project];
    }

    /** Карточку заказа своего цеха бригадир открывает. */
    public function test_foreman_opens_the_workshop_card(): void
    {
        [, $project] = $this->order();

        $this->actingAs($this->foreman)->get(route('projects.show', $project->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Projects/Show')->etc());
    }

    /** И видит в ней, что делать: адрес, позиции сделки и заметку менеджера. */
    public function test_the_card_shows_what_to_do(): void
    {
        [, $project] = $this->order();

        $this->actingAs($this->foreman)->get(route('projects.show', $project->id))
            ->assertInertia(fn ($page) => $page
                ->where('project.deal.address', 'г. Шымкент, ул. Промышленная 1')
                ->where('project.deal.note', 'Серый графит, не глянец')
                ->where('project.deal.items.0.name', 'Плитка «Кирпичик» 200×100×60')
                ->where('project.deal.items.0.unit', 'м²')
                ->etc());
    }

    /** И кто ведёт заказ: менеджер со стороны продаж, бригадир со стороны цеха. */
    public function test_the_card_shows_the_manager_and_the_foreman(): void
    {
        $manager = $this->manager();
        [, $project] = $this->order([
            'responsible_user_id' => $manager->id,
            'foreman_id' => $this->foreman->id,
        ]);

        $this->actingAs($this->foreman)->get(route('projects.show', $project->id))
            ->assertInertia(fn ($page) => $page
                ->where('project.deal.responsible.name', 'Бахытжан Сламбек')
                ->where('project.deal.foreman.name', 'Асхат Бекболат')
                ->etc());
    }

    /**
     * Денег в карточке цеха бригадиру не приходит.
     *
     * Проверяем ответ сервера, а не шаблон: сумма, доехавшая до браузера, —
     * уже утечка. Цена позиции тоже сумма: по ней считается вся сделка.
     */
    public function test_the_card_carries_no_money(): void
    {
        [$deal, $project] = $this->order(['foreman_id' => $this->foreman->id]);
        Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 900000, 'date' => now()->toDateString(), 'status' => 'confirmed',
        ]);

        $this->actingAs($this->foreman)->get(route('projects.show', $project->id))
            ->assertInertia(fn ($page) => $page
                ->where('canSeeMoney', false)
                ->where('finance', null)
                ->where('financeInvoices', [])
                ->where('financeExpenses', [])
                ->where('project', fn ($p) => ! $p->has('budget'))
                ->where('project.deal.items.0', fn ($item) => ! $item->has('price') && ! $item->has('amount'))
                ->etc());
    }

    /** Заказ своего цеха бригадир двигает по этапам. */
    public function test_foreman_moves_the_workshop_stage(): void
    {
        [, $project] = $this->order();
        $next = ProjectStage::where('workshop', 'Шымкент')->orderBy('order')->skip(1)->value('id');

        $this->actingAs($this->foreman)
            ->patch(route('projects.stage', $project->id), ['project_stage_id' => $next])
            ->assertSessionHasNoErrors();

        $this->assertSame($next, $project->fresh()->project_stage_id);
    }

    /**
     * Ссылка в саму сделку — только назначенному бригадиру: остальным
     * DealPolicy сделку не отдаст, а ссылка, ведущая в 403, хуже её отсутствия.
     */
    public function test_the_deal_link_is_only_for_the_assigned_foreman(): void
    {
        [, $mine] = $this->order(['foreman_id' => $this->foreman->id]);
        [, $someone_elses] = $this->order();

        $this->actingAs($this->foreman)->get(route('projects.show', $mine->id))
            ->assertInertia(fn ($page) => $page->where('canOpenDeal', true)->etc());

        $this->actingAs($this->foreman)->get(route('projects.show', $someone_elses->id))
            ->assertInertia(fn ($page) => $page->where('canOpenDeal', false)->etc());
    }

    /**
     * Фото объекта, снятое менеджером в сделке, видно в цехе.
     *
     * Снимки лежат у сделки, а карточка грузила только свои — в цехе фото
     * не было ни у бригадира, ни у менеджера.
     */
    public function test_photos_from_the_deal_are_visible_in_the_workshop(): void
    {
        $manager = $this->manager();
        [$deal, $project] = $this->order(['responsible_user_id' => $manager->id]);

        $this->actingAs($manager)->post(route('documents.store'), [
            'documentable_type' => 'deal',
            'documentable_id' => $deal->id,
            'file' => UploadedFile::fake()->image('obekt.jpg', 900, 700),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->foreman)->get(route('projects.show', $project->id))
            ->assertInertia(fn ($page) => $page
                ->where('project.deal.documents.0.name', 'obekt.jpg')
                ->etc());
    }

    /**
     * Бригадир открывает фото и прикрепляет своё.
     *
     * Показ картинки идёт через DocumentPolicy, а `document.*` цеховым ролям
     * не выдавали вовсе: фото в карточке отдавало 403, а кнопка загрузки не
     * работала. Снимок отливки делают как раз в цехе.
     */
    public function test_foreman_opens_and_attaches_photos(): void
    {
        [$deal, $project] = $this->order();

        // Прикрепить своё фото к заказу.
        $this->actingAs($this->foreman)->post(route('documents.store'), [
            'documentable_type' => 'project',
            'documentable_id' => $project->id,
            'file' => UploadedFile::fake()->image('otlivka.jpg', 900, 700),
        ])->assertSessionHasNoErrors();

        $mine = Document::firstWhere('documentable_type', 'project');
        $this->assertSame($this->foreman->id, $mine->user_id);

        // И открыть его, и чужое — из сделки, которую он ведёт.
        $this->actingAs($this->foreman)->get(route('documents.preview', $mine->id))->assertOk();
    }

    /** Своё фото бригадир убирает сам, чужой договор — нет. */
    public function test_foreman_deletes_only_his_own_file(): void
    {
        $manager = $this->manager();
        [$deal, $project] = $this->order(['responsible_user_id' => $manager->id]);

        $this->actingAs($manager)->post(route('documents.store'), [
            'documentable_type' => 'deal', 'documentable_id' => $deal->id,
            'file' => UploadedFile::fake()->create('dogovor.pdf', 10, 'application/pdf'),
        ]);
        $this->actingAs($this->foreman)->post(route('documents.store'), [
            'documentable_type' => 'project', 'documentable_id' => $project->id,
            'file' => UploadedFile::fake()->image('otlivka.jpg', 600, 400),
        ]);

        $contract = Document::firstWhere('name', 'dogovor.pdf');
        $photo = Document::firstWhere('name', 'otlivka.jpg');

        $this->actingAs($this->foreman)->delete(route('documents.destroy', $contract->id))->assertForbidden();
        $this->actingAs($this->foreman)->delete(route('documents.destroy', $photo->id))->assertSessionHasNoErrors();

        $this->assertModelExists($contract);
        $this->assertModelMissing($photo);
    }

    /** Цех другого города для бригадира по-прежнему закрыт. */
    public function test_another_city_stays_closed(): void
    {
        [, $almaty] = $this->order([], 'Алматы');

        $shymkentOnly = User::factory()->create(['workshops' => ['Шымкент']]);
        $shymkentOnly->assignRole('foreman');
        $shymkentOnly->companies()->attach($this->company);

        $this->actingAs($shymkentOnly)->get(route('projects.show', $almaty->id))->assertForbidden();
    }
}
