<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Финансист-бухгалтер тоже заводит сделки (кнопка «+ Сделка»). */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('financist', 'web')
            ->givePermissionTo(Permission::findOrCreate('deal.create', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'financist')->first()?->revokePermissionTo('deal.create');
    }
};
