<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Роль с признаками: чем она ЯВЛЯЕТСЯ, а не только что ей разрешено.
 *
 * Права отвечают «пустят ли в раздел». Признаки отвечают на другое: показывать
 * ли суммы, видит ли роль все сделки или только свои, пускать ли на доску
 * цеха. Раньше это было зашито именами ролей, и роль, созданная владельцем,
 * получалась немой — галочки есть, поведения нет.
 *
 * Имя роли (`name`) остаётся КОДОМ: на нём держатся политики и запасные
 * проверки. Переименовывают `label` — тот же принцип, что у `stage_type` (§6):
 * подпись меняется, логика нет.
 */
class Role extends SpatieRole
{
    protected $fillable = ['name', 'label', 'guard_name', 'is_leadership', 'sees_money', 'is_workshop', 'is_system'];

    protected $casts = [
        'is_leadership' => 'boolean',
        'sees_money' => 'boolean',
        'is_workshop' => 'boolean',
        'is_system' => 'boolean',
    ];

    /** Как роль называется у владельца; нет подписи — показываем код. */
    public function title(): string
    {
        return $this->label ?: $this->name;
    }
}
