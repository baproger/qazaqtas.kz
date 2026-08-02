<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Финансист редактирует и деактивирует сотрудников (админов — только админ). */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('financist', 'web')->givePermissionTo(
            collect(['user.update', 'user.delete'])
                ->map(fn ($p) => Permission::findOrCreate($p, 'web'))->all()
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'financist')->first()
            ?->revokePermissionTo(['user.update', 'user.delete']);
    }
};
