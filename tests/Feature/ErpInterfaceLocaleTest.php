<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\UiTranslation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Словарь интерфейса ERP: ключом служит русский текст.
 */
class ErpInterfaceLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_kazakh_dictionary_translates_the_russian_key(): void
    {
        $map = UiTranslation::map('kk');

        $this->assertSame('Сақтау', $map['erp.Сохранить']);
        $this->assertSame('Мәмілелер', $map['erp.Сделки']);
    }

    /**
     * Главное свойство этой схемы: на русском интерфейсе казахский текст
     * появиться не может. Запасной язык подставляется только там, где ключ
     * ничего не значит сам по себе, — у витрины.
     */
    public function test_russian_interface_never_falls_back_to_kazakh(): void
    {
        $map = UiTranslation::map('ru');

        $kazakh = array_filter(
            array_keys($map),
            fn (string $key) => str_starts_with($key, 'erp.'),
        );

        $this->assertSame([], $kazakh, 'Русский словарь не должен содержать строк ERP: их заменяет сам ключ.');
    }

    public function test_site_keys_still_fall_back_to_the_other_language(): void
    {
        // У витрины ключ непрозрачный (`site.nav.catalog`), и показать текст
        // на другом языке лучше, чем голый ключ.
        $map = UiTranslation::map('ru');

        $this->assertSame('Каталог', $map['site.nav.catalog']);
        $this->assertArrayHasKey('site.home.title', $map);
    }

    public function test_owner_override_wins_over_the_shipped_dictionary(): void
    {
        UiTranslation::create([
            'key' => 'erp.Сохранить',
            'group' => 'erp',
            'kk' => 'Сақтап қою',
        ]);

        $this->assertSame('Сақтап қою', UiTranslation::map('kk')['erp.Сохранить']);
    }

    public function test_erp_page_ships_the_dictionary_of_the_employee_language(): void
    {
        // Ключ сам содержит точку («erp.Сохранить»), поэтому читаем словарь
        // из пропсов напрямую: точечный путь assertInertia разобрал бы его
        // как вложенность и ничего не нашёл.
        $kazakh = $this->dictionaryFor('kk');

        $this->assertSame('Сақтау', $kazakh['erp.Сохранить']);

        $russian = $this->dictionaryFor('ru');

        $this->assertArrayNotHasKey('erp.Сохранить', $russian);
        $this->assertSame('Каталог', $russian['site.nav.catalog']);
    }

    /** Словарь, уехавший на страницу ERP сотруднику с этим языком. */
    private function dictionaryFor(string $locale): array
    {
        $user = User::factory()->create(['language' => $locale]);
        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('locale', $locale));

        return $response->viewData('page')['props']['translations'];
    }

    public function test_admin_page_lists_shipped_strings_not_only_saved_ones(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // До этой страницы базовый текст жил только в репозитории, и править
        // его через админку было нечего: список показывал одни правки.
        $this->actingAs($admin)->get(route('translations.index', ['group' => 'erp', 'search' => 'Сохранить переводы']))
            ->assertInertia(fn ($page) => $page
                ->where('items.0.name', 'Сохранить переводы')
                ->where('items.0.key', 'erp.Сохранить переводы')
                ->where('items.0.shipped.kk', 'Аудармаларды сақтау')
                ->where('items.0.shipped.ru', 'Сохранить переводы')
                ->where('items.0.override.kk', ''));
    }

    public function test_clearing_an_override_brings_back_the_shipped_text(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        UiTranslation::create(['key' => 'erp.Сохранить', 'group' => 'erp', 'kk' => 'Сақтап қою']);
        $this->assertSame('Сақтап қою', UiTranslation::map('kk')['erp.Сохранить']);

        // Пустая правка означает «как в поставке», а не «пустой текст».
        $this->actingAs($admin)->put(route('translations.update'), [
            'items' => [['key' => 'erp.Сохранить', 'group' => 'erp', 'kk' => '', 'ru' => '']],
        ])->assertRedirect();

        $this->assertSame('Сақтау', UiTranslation::map('kk')['erp.Сохранить']);
        $this->assertDatabaseMissing('ui_translations', ['key' => 'erp.Сохранить']);
    }

    /**
     * Словарь собран из шаблонов: если строку в шаблоне поменяли, а ключ
     * здесь оставили прежним, перевод тихо перестанет находиться. Тест
     * ловит расхождение до того, как его увидит сотрудник.
     */
    public function test_every_dictionary_key_is_still_used_in_the_interface(): void
    {
        $dictionary = array_keys(require base_path('lang/kk/erp.php'));
        $used = array_merge($this->stringsUsedInTemplates(), $this->stringsUsedByTheServer());

        $orphans = array_values(array_diff($dictionary, $used));

        $this->assertSame([], $orphans, 'В словаре есть строки, которых больше нет в шаблонах.');
    }

    /**
     * Строки, которые переводит СЕРВЕР по динамическому ключу.
     *
     * Подписи ролей приходят из БД (`roles.label`), а подпись — это русский
     * текст, то есть готовый ключ словаря. Регулярка по шаблонам их не найдёт
     * никогда: в шаблоне стоит `roleTitle(code)`, а не литерал. Берём их
     * оттуда же, откуда берёт приложение, — из ролей.
     *
     * @return array<int, string>
     */
    private function stringsUsedByTheServer(): array
    {
        $this->seed(RolePermissionSeeder::class);

        return Role::whereNotNull('label')->pluck('label')->all();
    }

    public function test_every_interface_string_has_a_kazakh_translation(): void
    {
        $dictionary = require base_path('lang/kk/erp.php');
        $missing = array_values(array_diff($this->stringsUsedInTemplates(), array_keys($dictionary)));

        $this->assertSame([], $missing, 'Эти строки интерфейса остались без казахского перевода.');
    }

    /** Все аргументы $e()/tr() из шаблонов ERP. */
    private function stringsUsedInTemplates(): array
    {
        $found = [];

        foreach ($this->erpTemplates() as $file) {
            preg_match_all(
                "/(?<![\\w.\$])(?:\\\$e|tr)\('((?:[^'\\\\]|\\\\.)*)'\)/u",
                file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $raw) {
                $found[] = str_replace("\\'", "'", $raw);
            }
        }

        return array_values(array_unique($found));
    }

    /** @return iterable<string> */
    private function erpTemplates(): iterable
    {
        $root = resource_path('js');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            $path = $file->getPathname();

            // Витрина живёт на своих ключах и в этот словарь не входит.
            if (! str_ends_with($path, '.vue')
                || str_contains($path, '/Site/')
                || str_contains($path, '/site/')
                || str_ends_with($path, 'SiteLayout.vue')) {
                continue;
            }

            yield $path;
        }
    }
}
