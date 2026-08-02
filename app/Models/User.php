<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'department_id', 'workshops', 'phone', 'birth_date', 'hired_at', 'salary', 'contract_path', 'avatar', 'language', 'is_active'])]
// salary/contract_path скрыты по умолчанию: не утекут при случайной
// сериализации сырой модели User во фронт. Админ-список читает их явно
// ($u->salary) — на прямой доступ $hidden не влияет.
#[Hidden(['password', 'remember_token', 'salary', 'contract_path'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'salary' => 'decimal:2',
            'birth_date' => 'date',
            'hired_at' => 'date',
            'workshops' => 'array',
        ];
    }

    /**
     * Primary department the user belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * All departments the user is a member of (many-to-many).
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    /**
     * Companies (BAIA / ASU) the user works for; one user may belong to both.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }

    /**
     * Изоляция фирм: принадлежит ли пользователь компании сущности.
     * null = сущность без компании (легаси/тесты) — доступна всем.
     */
    public function worksInCompany(?int $companyId): bool
    {
        // Админ — глобальный доступ ко всем фирмам (полный доступ везде),
        // как и Gate::before для политик. Иначе изоляция по компаниям в
        // ExpenseController/InvoiceController/… блокировала бы и админа.
        if ($this->hasRole('admin')) {
            return true;
        }

        return $companyId === null || $this->companies()->where('companies.id', $companyId)->exists();
    }

    /**
     * Доступ к цеху BAIA («Металл цех» / «Ағаш цех»). Пустой список у
     * сотрудника = ограничения нет; заказ без цеха (ASU) доступен всем.
     */
    public function worksInWorkshop(?string $workshop): bool
    {
        $allowed = $this->workshops ?? [];

        return $workshop === null || empty($allowed) || in_array($workshop, $allowed, true);
    }

    /**
     * Expose the stored avatar path as a served URL everywhere the model is
     * serialized (profile, sidebar, deals, chat). The raw path stays in the DB
     * (read it via getRawOriginal('avatar') when serving the file).
     */
    public function getAvatarAttribute(?string $value): ?string
    {
        return $value ? route('profile.avatar.show', $this->id).'?v='.optional($this->updated_at)->timestamp : null;
    }
}
