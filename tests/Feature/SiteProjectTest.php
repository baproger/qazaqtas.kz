<?php

namespace Tests\Feature;

use App\Models\SiteProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;

/**
 * Объекты сайта: ведутся в ERP, попадают на главную крупными кадрами
 * (только те, у которых есть фотография) и на страницу «Проекты».
 */
class SiteProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_project_is_created_and_gets_a_photo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('siteProjects.store'), [
            'title' => 'Центральный парк',
            'city' => 'Тараз',
            'year' => '2025',
            'area' => '7 800 м²',
            'is_active' => true,
        ])->assertRedirect();

        $project = SiteProject::firstOrFail();
        $this->assertNull($project->image);

        $this->actingAs($admin)->post(route('siteProjects.image', $project), [
            'image' => UploadedFile::fake()->image('park.jpg', 2000, 1200),
        ])->assertRedirect();

        $project->refresh();
        $this->assertStringStartsWith('/storage/projects/', $project->image);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $project->image));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $project->thumb));
    }

    public function test_project_photo_reaches_the_cards_on_the_site(): void
    {
        $admin = $this->admin();
        SiteProject::create(['title' => 'Без фото', 'is_active' => true, 'order' => 2]);

        $this->actingAs($admin)->post(route('siteProjects.store'), ['title' => 'С фото', 'is_active' => true, 'order' => 1]);
        $withPhoto = SiteProject::where('title', 'С фото')->firstOrFail();
        $this->actingAs($admin)->post(route('siteProjects.image', $withPhoto), [
            'image' => UploadedFile::fake()->image('obekt.jpg', 1600, 900),
        ]);

        // Оба объекта в блоке «Реализовано»; у первого карточка с фотографией,
        // у второго — бетонная заливка вместо снимка.
        foreach (['site.home', 'site.projects'] as $page) {
            $this->get(route($page))->assertInertia(fn ($p) => $p
                ->has('projects', 2)
                ->where('projects.0.title', 'С фото')
                ->whereNot('projects.0.image', null)
                ->where('projects.1.image', null));
        }
    }

    public function test_hidden_project_disappears_from_the_site(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('siteProjects.store'), ['title' => 'Скрытый', 'is_active' => true]);
        $project = SiteProject::firstOrFail();

        $this->get(route('site.projects'))->assertInertia(fn ($p) => $p->has('projects', 1));

        $this->actingAs($admin)->put(route('siteProjects.update', $project), [
            'title' => 'Скрытый', 'is_active' => false,
        ])->assertRedirect();

        $this->get(route('site.projects'))->assertInertia(fn ($p) => $p->has('projects', 0));
    }

    public function test_deleting_a_project_removes_its_photo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('siteProjects.store'), ['title' => 'Удаляемый', 'is_active' => true]);
        $project = SiteProject::firstOrFail();
        $this->actingAs($admin)->post(route('siteProjects.image', $project), [
            'image' => UploadedFile::fake()->image('x.jpg', 900, 600),
        ]);
        $image = $project->fresh()->image;

        $this->actingAs($admin)->delete(route('siteProjects.destroy', $project))->assertRedirect();

        $this->assertSame(0, SiteProject::count());
        Storage::disk('public')->assertMissing(str_replace('/storage/', '', $image));
    }

    public function test_managers_cannot_manage_site_projects(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)->get(route('siteProjects.index'))->assertForbidden();
    }
}
