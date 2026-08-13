<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Удаление медиа не должно трогать файлы, которые позиции не принадлежат.
 * Путь в JSON мог быть проставлен вручную или скопирован из другой записи.
 */
class MediaOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(array $attributes = []): Product
    {
        $category = ProductCategory::create([
            'name' => 'Вазоны', 'slug' => 'vazony', 'is_active' => true, 'order' => 0,
        ]);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Вазон «Астана»',
            'slug' => 'vazon-astana',
            'unit' => 'шт',
            'price' => 78000,
            'is_active' => true,
            'order' => 0,
        ], $attributes));
    }

    public function test_deleting_a_photo_never_touches_a_foreign_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/999/foreign.jpg', 'снимок другой позиции');

        $product = $this->product(['images' => [
            ['path' => '/storage/catalog/999/foreign.jpg', 'thumb' => '/storage/catalog/999/foreign-thumb.jpg', 'alt' => ''],
        ]]);

        $this->actingAs($this->admin())
            ->delete(route('catalogMedia.imageDestroy', $product->id), ['index' => 0])
            ->assertRedirect();

        // Ссылка из позиции ушла, а файл остался: он не её.
        $this->assertSame([], $product->fresh()->images);
        Storage::disk('public')->assertExists('catalog/999/foreign.jpg');
    }

    public function test_deleting_its_own_photo_removes_the_file(): void
    {
        Storage::fake('public');
        $product = $this->product();

        Storage::disk('public')->put("catalog/{$product->id}/own.jpg", 'свой снимок');
        $product->update(['images' => [
            ['path' => "/storage/catalog/{$product->id}/own.jpg", 'thumb' => null, 'alt' => ''],
        ]]);

        $this->actingAs($this->admin())
            ->delete(route('catalogMedia.imageDestroy', $product->id), ['index' => 0])
            ->assertRedirect();

        Storage::disk('public')->assertMissing("catalog/{$product->id}/own.jpg");
    }

    public function test_deleting_a_document_never_touches_a_foreign_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/999/spec.pdf', 'чужой документ');

        $product = $this->product(['documents' => [
            ['name' => 'Паспорт', 'path' => '/storage/catalog/999/spec.pdf'],
        ]]);

        $this->actingAs($this->admin())
            ->delete(route('catalogMedia.documentDestroy', $product->id), ['index' => 0])
            ->assertRedirect();

        Storage::disk('public')->assertExists('catalog/999/spec.pdf');
    }

    public function test_category_photo_upload_requires_the_update_right(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('employee');

        $category = ProductCategory::create([
            'name' => 'Урны', 'slug' => 'urny', 'is_active' => true, 'order' => 0,
        ]);

        $this->actingAs($manager)
            ->delete(route('catalogCategories.imageDestroy', $category->id))
            ->assertForbidden();
    }
}
