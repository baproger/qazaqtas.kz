<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            StageSeeder::class,
            DealCustomFieldSeeder::class,
            UiTranslationSeeder::class,
        ]);

        $department = Department::firstOrCreate(
            ['name' => 'Отдел продаж'],
            ['description' => 'Основной отдел продаж', 'is_active' => true]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@baia.kz'],
            [
                'name' => 'Администратор',
                'password' => Hash::make('password'),
                'department_id' => $department->id,
                'language' => 'ru',
                'is_active' => true,
            ]
        );
        $admin->assignRole('admin');
        $admin->departments()->syncWithoutDetaching([$department->id]);
    }
}
