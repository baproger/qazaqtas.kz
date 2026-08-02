<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'workshop', 'name', 'order', 'color', 'checklist', 'type', 'is_completed', 'is_active',
    ];

    protected $casts = [
        'checklist' => 'array',
        'is_completed' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Цех у каждой компании СВОЙ (BAIA — мебельный, ASU — швейный). Если у
     * фирмы есть СВОИ этапы — показываем только их; «общие» (company_id=null,
     * легаси/тесты) — ТОЛЬКО как фолбэк, иначе одинаковые названия двоятся
     * (Кесу+Кесу…) в степпере заказа.
     */
    public static function companyQuery(?int $companyId, ?string $workshop = null): \Illuminate\Database\Eloquent\Builder
    {
        $q = static::where('is_active', true)->orderBy('order')->orderBy('id');

        if ($companyId) {
            static::where('is_active', true)->where('company_id', $companyId)->exists()
                ? $q->where('company_id', $companyId)
                : $q->whereNull('company_id');
        }

        // Цех внутри компании (у BAIA их два — «Металл цех» и «Ағаш цех»):
        // воронка конкретного цеха. null = единственный цех (ASU, легаси).
        if ($workshop !== null) {
            $q->where('workshop', $workshop);
        }

        return $q;
    }

    public static function funnel(?int $companyId = null, ?string $workshop = null): \Illuminate\Support\Collection
    {
        return static::companyQuery($companyId, $workshop)->get();
    }

    /** Названия цехов компании (пусто или один элемент = выбор не нужен). */
    public static function workshopsFor(?int $companyId): array
    {
        // reorder(): companyQuery сортирует по order/id, а MySQL запрещает
        // DISTINCT/GROUP BY с ORDER BY по невыбранным колонкам (ошибка 3065).
        // Цеха сортируем по позиции их первого этапа (MIN(order)).
        return static::companyQuery($companyId)
            ->whereNotNull('workshop')
            ->reorder()
            ->groupBy('workshop')
            ->orderByRaw('MIN(`order`) asc')
            ->pluck('workshop')->all();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProjectStageTranslation::class);
    }

    public function translatedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->translations
            ->firstWhere('locale', $locale)?->name ?? $this->name;
    }
}
