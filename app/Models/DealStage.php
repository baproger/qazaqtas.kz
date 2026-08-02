<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealStage extends Model
{
    use HasFactory;

    /**
     * Системные типы этапов: логика (гейты, права, won, возврат из цеха)
     * держится на stage_type, а НЕ на названии — этапы можно свободно
     * переименовывать. Типы с логикой: shop_gate (кнопка «В цех»), logistics
     * (возврат из цеха), act / esf (ведёт бухгалтер), payment_won (won).
     */
    public const STAGE_TYPES = [
        'contract' => 'Заключение договора',
        'design' => 'Дизайн и расчёт',
        'shop_gate' => 'Закуп / отправка в цех',
        'logistics' => 'Логистика (возврат из цеха)',
        'assembly' => 'Сборка',
        'act' => 'Акт утверждения (бухгалтер)',
        'esf' => 'ЭСФ (бухгалтер)',
        'payment_won' => 'Оплата успешно (won)',
    ];

    protected $fillable = [
        'company_id', 'name', 'order', 'color', 'checklist', 'type', 'stage_type',
        'gate_task_title', 'gate_task_role', 'gate_task_days', 'is_won', 'is_active',
    ];

    protected $casts = [
        'checklist' => 'array',
        'is_won' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * У каждой компании (BAIA/ASU) СВОЯ воронка: этапы с company_id видны
     * только своей фирме; этапы без company_id — общие (легаси/тесты).
     * Спец-этапы ищутся ПО ТИПУ (stage_type), не по названию и не по позиции:
     * этапы можно переименовывать и перемещать без поломки логики.
     */

    /** Активная воронка компании (+ общие этапы без компании). */
    public static function funnel(?int $companyId = null): \Illuminate\Support\Collection
    {
        // orderBy('id') — детерминированный тай-брейк, если order задвоился:
        // порядок в воронке и переход «Далее» остаются стабильными.
        return static::where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->orderBy('id')->get();
    }

    /** Этап воронки по системному типу. */
    public static function ofType(string $type, ?int $companyId = null): ?self
    {
        return self::funnel($companyId)->firstWhere('stage_type', $type);
    }

    /** «Акт утверждение» — с него сделку ведёт бухгалтер. */
    public static function actStage(?int $companyId = null): ?self
    {
        return self::ofType('act', $companyId) ?? self::funnel($companyId)->slice(-2, 1)->first();
    }

    /** «ЭСФ» — после акта. Может отсутствовать в воронке. */
    public static function esfStage(?int $companyId = null): ?self
    {
        return self::ofType('esf', $companyId);
    }

    /** «Оплата успешно» — payment_won (is_won синхронизирован с типом). */
    public static function wonStage(?int $companyId = null): ?self
    {
        $active = self::funnel($companyId);

        return $active->firstWhere('stage_type', 'payment_won')
            ?? $active->firstWhere('is_won', true) ?? $active->last();
    }

    /** «Логистика» — сюда цех возвращает сделку после производства. */
    public static function logisticsStage(?int $companyId = null): ?self
    {
        return self::ofType('logistics', $companyId);
    }

    /** Этап-ворота в цех — на нём доступна кнопка «В цех». */
    public static function workshopGateStage(?int $companyId = null): ?self
    {
        return self::ofType('shop_gate', $companyId) ?? self::funnel($companyId)->slice(-3, 1)->first();
    }

    /** Настроен ли на этапе гейт (задача на входе, блокирующая выход). */
    public function hasGate(): bool
    {
        return ! empty($this->gate_task_title) && (int) $this->gate_task_days > 0;
    }

    public function translations(): HasMany
    {
        return $this->hasMany(DealStageTranslation::class);
    }

    /**
     * Localised name for the given (or current) locale, falling back to base name.
     */
    public function translatedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->translations
            ->firstWhere('locale', $locale)?->name ?? $this->name;
    }
}
