<?php

namespace App\Console\Commands;

use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;

/**
 * Вернуть системные роли, удалённые из админки.
 *
 * Роли удаляет владелец — это его штатное расписание. Но у системных ролей
 * есть вторая жизнь: на их имена ссылается код (кому уходит уведомление, кто
 * закрывает гейт-этап). Удалил «Директора» — рассылки о нехватке склада
 * замолчали, а сам он пропал из списка ответственных.
 *
 * Команда создаёт ТОЛЬКО недостающие роли и не трогает те, что на месте:
 * иначе она затёрла бы права, которые владелец сам разложил в матрице.
 */
class RestoreSystemRoles extends Command
{
    protected $signature = 'roles:restore {--dry : только показать, чего не хватает}';

    protected $description = 'Вернуть удалённые системные роли с их правами по умолчанию.';

    public function handle(): int
    {
        $before = Role::pluck('name')->all();
        $expected = RolePermissionSeeder::systemRoles();
        $missing = array_values(array_diff($expected, $before));

        if ($missing === []) {
            $this->info('Все системные роли на месте.');

            return self::SUCCESS;
        }

        $this->warn('Недостают: '.implode(', ', $missing));

        if ($this->option('dry')) {
            return self::SUCCESS;
        }

        // Сидер идемпотентен (findOrCreate), но права он синхронизирует всем
        // подряд. Чтобы не затереть настройки владельца, запоминаем права
        // существующих ролей и возвращаем их обратно.
        $keep = Role::whereIn('name', $before)->with('permissions')->get()
            ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->all()]);

        (new RolePermissionSeeder)->run();

        foreach ($keep as $name => $permissions) {
            Role::where('name', $name)->first()?->syncPermissions($permissions);
        }

        $this->info('Возвращено ролей: '.count($missing).'. Настройки остальных сохранены.');

        return self::SUCCESS;
    }
}
