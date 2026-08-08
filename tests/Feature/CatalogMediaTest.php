<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\CatalogService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Медиа каталога: фото товара, текстура для 3D, GLB-модель и документы.
 * Загруженное фото сразу видно и на витрине, и в 3D-сценах.
 */
class CatalogMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CatalogSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function paving(): Product
    {
        return Product::where('code', 'QT-P-300')->firstOrFail();
    }

    public function test_uploaded_photo_is_resized_and_shown_on_the_site(): void
    {
        $product = $this->paving();

        $this->actingAs($this->admin())
            ->post(route('catalogMedia.images', $product), [
                'images' => [UploadedFile::fake()->image('plitka.jpg', 2400, 1600)],
            ])->assertRedirect();

        $product->refresh();
        $this->assertCount(1, $product->images);

        // Рядом с веб-версией лежит превью — витрина отдаёт его через srcset.
        [$image] = $product->images;
        $this->assertStringStartsWith('/storage/catalog/'.$product->id.'/', $image['path']);
        $this->assertStringContainsString('-thumb', $image['thumb']);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $image['path']));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $image['thumb']));

        $this->get(route('site.product', $product->slug))
            ->assertInertia(fn ($p) => $p->where('product.images.0.path', $image['path']));
    }

    public function test_photo_marked_as_texture_reaches_the_3d_scene(): void
    {
        $product = $this->paving();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('catalogMedia.images', $product), [
            'images' => [UploadedFile::fake()->image('poverhnost.jpg', 1200, 1200)],
        ]);

        // До отметки сцена работает на цвете.
        $this->assertSame([], app(CatalogService::class)->sceneAssets()['textures']);

        $this->actingAs($admin)->post(route('catalogMedia.texture', $product), ['index' => 0])
            ->assertRedirect();

        $texture = $product->fresh()->texture_path;
        $this->assertNotNull($texture);

        // Главная отдаёт текстуру сцене, конфигуратор — коллекции плитки.
        $this->get(route('site.home'))
            ->assertInertia(fn ($p) => $p->where('scene.textures.paving', $texture));
        $this->get(route('site.configurator'))
            ->assertInertia(fn ($p) => $p->where('collections.0.texture', $texture));
    }

    public function test_deleting_a_photo_clears_the_texture_and_the_files(): void
    {
        $product = $this->paving();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('catalogMedia.images', $product), [
            'images' => [UploadedFile::fake()->image('one.jpg', 800, 800)],
        ]);
        $this->actingAs($admin)->post(route('catalogMedia.texture', $product), ['index' => 0]);

        $image = $product->fresh()->images[0];

        $this->actingAs($admin)->delete(route('catalogMedia.imageDestroy', $product), ['index' => 0])
            ->assertRedirect();

        $product->refresh();
        $this->assertSame([], $product->images);
        $this->assertNull($product->texture_path);
        Storage::disk('public')->assertMissing(str_replace('/storage/', '', $image['path']));
        Storage::disk('public')->assertMissing(str_replace('/storage/', '', $image['thumb']));
    }

    public function test_main_photo_can_be_reordered(): void
    {
        $product = $this->paving();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('catalogMedia.images', $product), [
            'images' => [
                UploadedFile::fake()->image('first.jpg', 600, 600),
                UploadedFile::fake()->image('second.jpg', 600, 600),
            ],
        ]);

        $second = $product->fresh()->images[1]['path'];

        $this->actingAs($admin)->post(route('catalogMedia.imageMain', $product), ['index' => 1])
            ->assertRedirect();

        $this->assertSame($second, $product->fresh()->images[0]['path']);
    }

    public function test_model_and_documents_are_attached_to_the_card(): void
    {
        $product = $this->paving();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('catalogMedia.model', $product), [
            'model' => UploadedFile::fake()->create('vazon.glb', 512),
        ])->assertRedirect();
        $this->assertNotNull($product->fresh()->model_path);

        $this->actingAs($admin)->post(route('catalogMedia.document', $product), [
            'name' => 'Паспорт изделия',
            'document' => UploadedFile::fake()->create('passport.pdf', 128, 'application/pdf'),
        ])->assertRedirect();

        $documents = $product->fresh()->documents;
        $this->assertCount(1, $documents);
        $this->assertSame('Паспорт изделия', $documents[0]['name']);

        // Витрина показывает документ в карточке.
        $this->get(route('site.product', $product->slug))
            ->assertInertia(fn ($p) => $p->where('product.documents.0.name', 'Паспорт изделия'));
    }

    public function test_erp_routes_accept_product_id_not_slug(): void
    {
        // Интерфейс ERP оперирует id: slug меняется вместе с названием.
        $product = $this->paving();

        $this->actingAs($this->admin())
            ->put('/catalog/'.$product->id, [
                'name' => 'Плитка «Квадрат» 300×300×60',
                'unit' => 'м²',
                'price' => 9100,
            ])->assertRedirect();

        $this->assertSame(9100.0, (float) $product->fresh()->price);
    }

    public function test_media_is_closed_for_users_without_rights(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('employee');

        $this->actingAs($worker)
            ->post(route('catalogMedia.images', $this->paving()), [
                'images' => [UploadedFile::fake()->image('x.jpg')],
            ])->assertForbidden();
    }
}
