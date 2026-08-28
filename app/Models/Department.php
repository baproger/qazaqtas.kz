<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['parent_id', 'sort', 'name', 'description', 'head_user_id', 'is_active'];

    /** Отдел, которому этот подчинён. Пусто — корень структуры. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Прямые подчинённые отделы. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    /**
     * Этот отдел и ВСЕ подчинённые ему, вглубь.
     *
     * Считаем в PHP по одному запросу, а не рекурсией по БД: отделов в
     * компании десятки, и рекурсивный CTE ради них — сложность без выгоды.
     * Цикл (отдел стал подчинённым сам себе через цепочку) обрывается через
     * $seen: без этого одна кривая запись вешала бы страницу.
     *
     * @return array<int, int>
     */
    public function subtreeIds(): array
    {
        $byParent = static::query()->get(['id', 'parent_id'])->groupBy('parent_id');

        $ids = [];
        $queue = [$this->id];
        $seen = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;

            foreach ($byParent->get($id, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    protected $casts = ['is_active' => 'boolean'];

    /** Руководитель отдела: ⭐ на карточке сотрудника + уведомления по отделу. */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
