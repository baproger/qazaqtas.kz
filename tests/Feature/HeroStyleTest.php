<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteContent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Оформление первого экрана и подготовка снимков под него. */
class HeroStyleTest extends TestCase
{
    use RefreshDatabase;

    private function category(array $attributes = []): ProductCategory
    {
        return ProductCategory::create(array_merge([
            'name' => 'Вазоны',
            'slug' => 'vazony',
            'tagline' => 'Уличные кашпо для благоустройства',
            'is_active' => true,
            'order' => 0,
        ], $attributes));
    }

    private function product(ProductCategory $category, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Вазон «Астана» Ø900',
            'slug' => 'vazon-astana-o900',
            'unit' => 'шт',
            'price' => 78000,
            'min_order' => 1,
            'is_active' => true,
            'order' => 0,
            'specs' => ['size' => 'Ø 900 × 700 мм', 'frost' => 'F200', 'thickness_mm' => 60],
        ], $attributes));
    }

    public function test_scene3d_is_the_default(): void
    {
        $this->assertSame('scene3d', SiteContent::heroStyle());

        $this->get(route('site.home'))
            ->assertInertia(fn ($p) => $p->where('hero', 'scene3d'));
    }

    public function test_a_slide_is_built_per_category_with_a_photo(): void
    {
        $category = $this->category(['image' => '/storage/categories/1/a.png', 'thumb' => '/storage/categories/1/a-thumb.png']);
        $this->product($category);
        $this->product($category, ['name' => 'Вазон «Куб»', 'slug' => 'vazon-kub', 'price' => 52000]);
        Setting::set('site_hero', 'showcase');

        $this->get(route('site.home'))->assertInertia(fn ($p) => $p
            ->where('hero', 'showcase')
            ->has('heroSlides', 1)
            ->where('heroSlides.0.id', 'vazony')
            ->where('heroSlides.0.category', 'ВАЗОНЫ')
            ->where('heroSlides.0.count', 2)
            // Цена — минимальная по разделу: это честное «от».
            ->where('heroSlides.0.price', 52000)
            ->where('heroSlides.0.image.path', '/storage/categories/1/a.png')
            // Порядок запасных подписей: размер первым — он нужнее всего.
            ->where('heroSlides.0.specs.0.pos', 'top-right')
            ->where('heroSlides.0.specs.0.value', 'Ø 900 × 700 мм')
            ->where('heroSlides.0.specs.1.value', 'F200'));
    }

    public function test_category_specs_win_over_the_fallback(): void
    {
        $category = $this->category([
            'image' => '/storage/categories/1/a.png',
            // Подписи относятся к снимку категории, а не к позиции каталога.
            'specs' => [
                ['label' => 'Диаметр', 'value' => 'Ø 900 мм'],
                ['label' => 'Высота', 'value' => '700 мм'],
            ],
        ]);
        $this->product($category);
        Setting::set('site_hero', 'showcase');

        $this->get(route('site.home'))->assertInertia(fn ($p) => $p
            ->has('heroSlides.0.specs', 2)
            ->where('heroSlides.0.specs.0.label', 'Диаметр')
            ->where('heroSlides.0.specs.0.pos', 'top-right')
            ->where('heroSlides.0.specs.1.label', 'Высота'));
    }

    public function test_categories_without_a_photo_never_become_slides(): void
    {
        $category = $this->category();
        $this->product($category);
        Setting::set('site_hero', 'showcase');

        // Без вырезанного снимка слайд выглядел бы дырой — не показываем.
        $this->get(route('site.home'))->assertInertia(fn ($p) => $p->has('heroSlides', 0));
    }

    public function test_hidden_category_stays_out_of_the_showcase(): void
    {
        $category = $this->category(['is_active' => false, 'image' => '/storage/categories/1/a.png']);
        $this->product($category);
        Setting::set('site_hero', 'showcase');

        $this->get(route('site.home'))->assertInertia(fn ($p) => $p->has('heroSlides', 0));
    }

    public function test_unknown_style_falls_back_to_the_scene(): void
    {
        Setting::set('site_hero', 'что-то-своё');

        $this->assertSame('scene3d', SiteContent::heroStyle());
    }

    public function test_admin_switches_the_hero_from_settings(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('siteSettings.update'), [
            'hero' => 'showcase',
            'phone' => '+7 707 372 22 22',
            'whatsapp' => '+7 771 610 77 70',
        ])->assertRedirect();

        $this->assertSame('showcase', SiteContent::heroStyle());
    }

    public function test_cut_out_png_keeps_its_transparency_after_upload(): void
    {
        Storage::fake('public');

        // Вырезанный предмет: непрозрачный круг на прозрачном фоне.
        $canvas = imagecreatetruecolor(300, 300);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagefilledellipse($canvas, 150, 150, 200, 200, imagecolorallocate($canvas, 200, 170, 110));
        $tmp = tempnam(sys_get_temp_dir(), 'qt').'.png';
        imagepng($canvas, $tmp);
        imagedestroy($canvas);

        $stored = app(\App\Services\MediaService::class)
            ->storeImage(new UploadedFile($tmp, 'render.png', 'image/png', null, true), 'hero-test');

        $this->assertStringEndsWith('.png', $stored['path']);

        $relative = ltrim(str_replace('/storage/', '', $stored['path']), '/');
        $result = imagecreatefromstring(Storage::disk('public')->get($relative));
        $alpha = (imagecolorat($result, 2, 2) >> 24) & 0x7F;

        // 127 — полностью прозрачно. Белой подложки под предметом быть не должно.
        $this->assertSame(127, $alpha, 'Углы PNG обязаны остаться прозрачными');

        @unlink($tmp);
    }

    public function test_category_photo_upload_rejects_jpeg(): void
    {
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $category = $this->category();

        $canvas = imagecreatetruecolor(200, 200);
        $tmp = tempnam(sys_get_temp_dir(), 'qt').'.jpg';
        imagejpeg($canvas, $tmp);
        imagedestroy($canvas);

        // JPG не умеет альфа-канал — на тёмной теме он станет белой заплаткой.
        $this->actingAs($admin)
            ->post(route('catalogCategories.image', $category->id), [
                'image' => new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true),
            ])
            ->assertSessionHasErrors('image');

        @unlink($tmp);
    }

    public function test_photographs_are_still_stored_as_jpeg(): void
    {
        Storage::fake('public');

        $canvas = imagecreatetruecolor(300, 300);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 120, 140, 160));
        $tmp = tempnam(sys_get_temp_dir(), 'qt').'.jpg';
        imagejpeg($canvas, $tmp);
        imagedestroy($canvas);

        $stored = app(\App\Services\MediaService::class)
            ->storeImage(new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true), 'hero-test');

        $this->assertStringEndsWith('.jpg', $stored['path']);

        @unlink($tmp);
    }
}
