<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Document;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\DealItemService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Фото принадлежит ТОВАРУ, а не сделке целиком.
 *
 * В цехе по снимку сверяют отливку конкретной плитки: «Ромб» выглядит так,
 * «Соты» — иначе. Общая куча снимков на весь заказ этого не даёт.
 *
 * Отсюда главное требование: позиция обязана пережить правку сделки. Раньше
 * сохранение переписывало строки заново (delete + createMany), и правка
 * количества молча осиротила бы все фото.
 */
class DealItemPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Deal $deal;

    private Product $romb;

    private Product $soty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $company = Company::where('code', 'QT')->value('id');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach($company);

        $this->romb = Product::create(['name' => 'Плитка «Ромб» 190×330×60', 'unit' => 'м²', 'price' => 9000, 'is_active' => true]);
        $this->soty = Product::create(['name' => 'Плитка «Соты» 265×230×60', 'unit' => 'м²', 'price' => 8000, 'is_active' => true]);

        $this->deal = Deal::create([
            'company_id' => $company,
            'number' => 'QT-900',
            'name' => 'Двор ЖК',
            'company_name' => 'ТОО «Клиент»',
            'address' => 'г. Шымкент',
            'status' => 'active',
            'responsible_user_id' => $this->manager->id,
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ]);

        app(DealItemService::class)->syncDeal($this->deal, [
            ['product_id' => $this->romb->id, 'quantity' => 210],
            ['product_id' => $this->soty->id, 'quantity' => 80],
        ]);
    }

    private function attach(int $itemId, string $name): Document
    {
        $this->actingAs($this->manager)->post(route('documents.store'), [
            'documentable_type' => 'deal_item',
            'documentable_id' => $itemId,
            'file' => UploadedFile::fake()->image($name, 900, 700),
        ])->assertSessionHasNoErrors();

        return Document::firstWhere('name', $name);
    }

    /** Снимок ложится к своей позиции, а не в общую кучу сделки. */
    public function test_a_photo_belongs_to_its_item(): void
    {
        $romb = $this->deal->items()->where('product_id', $this->romb->id)->first();
        $soty = $this->deal->items()->where('product_id', $this->soty->id)->first();

        $this->attach($romb->id, 'romb.jpg');
        $this->attach($soty->id, 'soty.jpg');

        $this->assertSame(['romb.jpg'], $romb->documents()->pluck('name')->all());
        $this->assertSame(['soty.jpg'], $soty->documents()->pluck('name')->all());
        // К самой сделке при этом ничего не прилипло.
        $this->assertCount(0, $this->deal->documents);
    }

    /**
     * Правка сделки не теряет фото.
     *
     * Менеджер поменял количество и добавил товар — позиция та же, значит и
     * снимки на месте. Это то, ради чего сохранение позиций переписано с
     * «удалить и создать заново» на обновление на месте.
     */
    public function test_photos_survive_editing_the_deal(): void
    {
        $romb = $this->deal->items()->where('product_id', $this->romb->id)->first();
        $photo = $this->attach($romb->id, 'romb.jpg');

        app(DealItemService::class)->syncDeal($this->deal, [
            ['product_id' => $this->romb->id, 'quantity' => 260],   // объём вырос
            ['product_id' => $this->soty->id, 'quantity' => 80],
            ['product_id' => $this->romb->id, 'quantity' => 15],    // добавили строку
        ]);

        $romb->refresh();
        $this->assertSame(260.0, (float) $romb->quantity, 'Позиция обновилась, а не пересоздалась');
        $this->assertSame($photo->id, $romb->documents()->value('id'), 'Фото осталось у своей позиции');
        $this->assertTrue(Storage::disk('local')->exists($photo->file_path));
    }

    /** Товар убрали из заказа — его снимки уходят вместе с ним, и файлы тоже. */
    public function test_removing_an_item_removes_its_photos(): void
    {
        $soty = $this->deal->items()->where('product_id', $this->soty->id)->first();
        $photo = $this->attach($soty->id, 'soty.jpg');
        $path = $photo->file_path;

        app(DealItemService::class)->syncDeal($this->deal, [
            ['product_id' => $this->romb->id, 'quantity' => 210],
        ]);

        $this->assertModelMissing($soty);
        $this->assertModelMissing($photo);
        $this->assertFalse(Storage::disk('local')->exists($path), 'Файл убран с диска, а не осиротел');
    }

    /**
     * Права позиции — права её сделки.
     *
     * Иначе снимок «Ромба» открывался бы по прямой ссылке тому, кому сама
     * сделка недоступна.
     */
    public function test_item_photos_inherit_the_deal_rights(): void
    {
        $romb = $this->deal->items()->where('product_id', $this->romb->id)->first();
        $photo = $this->attach($romb->id, 'romb.jpg');

        $stranger = User::factory()->create();
        $stranger->assignRole('manager');

        $this->actingAs($stranger)->get(route('documents.preview', $photo->id))->assertForbidden();
        $this->actingAs($stranger)->post(route('documents.store'), [
            'documentable_type' => 'deal_item',
            'documentable_id' => $romb->id,
            'file' => UploadedFile::fake()->image('chuzhoe.jpg', 400, 300),
        ])->assertForbidden();
    }

    /** Бригадир в цехе видит фото позиции и прикрепляет своё — без цен. */
    public function test_the_foreman_sees_and_adds_item_photos(): void
    {
        $foreman = User::factory()->create();
        $foreman->assignRole('foreman');
        $foreman->companies()->attach($this->deal->company_id);
        $this->deal->update(['foreman_id' => $foreman->id]);

        $romb = $this->deal->items()->where('product_id', $this->romb->id)->first();
        $this->attach($romb->id, 'ot-menedzhera.jpg');

        $project = Project::create([
            'number' => 'PRJ-900', 'name' => 'Двор ЖК', 'deal_id' => $this->deal->id,
            'workshop' => 'Шымкент', 'status' => 'active',
            'project_stage_id' => ProjectStage::where('workshop', 'Шымкент')->orderBy('order')->value('id'),
        ]);

        // Своё фото отливки — к той же позиции.
        $this->actingAs($foreman)->post(route('documents.store'), [
            'documentable_type' => 'deal_item',
            'documentable_id' => $romb->id,
            'file' => UploadedFile::fake()->image('otlivka.jpg', 800, 600),
        ])->assertSessionHasNoErrors();

        $this->actingAs($foreman)->get(route('projects.show', $project->id))
            ->assertInertia(fn ($page) => $page
                ->where('project.deal.items.0.documents', fn ($docs) => collect($docs)->pluck('name')->sort()->values()->all()
                    === ['ot-menedzhera.jpg', 'otlivka.jpg'])
                ->where('project.deal.items.0', fn ($item) => ! $item->has('price') && ! $item->has('amount'))
                ->etc());
    }
}
