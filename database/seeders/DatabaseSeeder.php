<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Стартовое наполнение QAZAQ TAS: роли, воронки сделки и цеха, склад
     * сырья, каталог витрины, отделы и учётка администратора
     * (пароль сменить сразу после первого входа).
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            StageSeeder::class,
            ServiceCategorySeeder::class,
            MaterialSeeder::class,
            CatalogSeeder::class,
            SiteProjectSeeder::class,
            SiteSettingsSeeder::class,
            DealCustomFieldSeeder::class,
            UiTranslationSeeder::class,
        ]);

        $departments = ['Отдел продаж' => 'Заявки, КП, сделки и клиенты'];

        // Производство в трёх городах — у каждого свой отдел и свой цех
        // (воронка этапов + ТВ-экран заводятся в StageSeeder).
        foreach (StageSeeder::WORKSHOPS as $city) {
            $departments['Производство — '.$city] = 'Формовка, шлифовка, упаковка изделий · '.$city;
        }

        $departments += [
            'Снабжение' => 'Закуп сырья и оснастки, склад',
            'Логистика и монтаж' => 'Доставка и монтаж на объекте',
            'Бухгалтерия' => 'Финансы, счета, зарплата, отчётность',
        ];

        $sales = null;
        foreach ($departments as $name => $description) {
            $dept = Department::firstOrCreate(['name' => $name], ['description' => $description, 'is_active' => true]);
            $sales ??= $dept;
        }

        // Пароль берём из окружения. Если его не задали — генерируем случайный
        // и печатаем один раз: молча оставлять всем известный «password»
        // на боевом сервере нельзя.
        $password = (string) env('ADMIN_PASSWORD', '');
        if ($password === '') {
            $password = Str::password(16);
            $this->command?->warn('Пароль администратора не задан в ADMIN_PASSWORD.');
            $this->command?->warn("Сгенерирован: {$password}");
            $this->command?->warn('Сохраните его — второй раз он показан не будет.');
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@qazaqtas.kz'],
            [
                'name' => 'Администратор',
                'password' => Hash::make($password),
                'department_id' => $sales->id,
                'language' => 'ru',
                'is_active' => true,
            ]
        );
        $admin->assignRole('admin');
        $admin->departments()->syncWithoutDetaching([$sales->id]);

        // Доступ администратора к фирме (мультикомпанийный режим).
        if ($companyId = Company::orderBy('id')->value('id')) {
            $admin->companies()->syncWithoutDetaching([$companyId]);
        }
    }
}
