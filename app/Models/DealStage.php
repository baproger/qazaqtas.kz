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
        'design' => 'Замер и расчёт (технолог)',
        'shop_gate' => 'Закуп сырья / отправка в цех',
        'logistics' => 'Логистика (возврат из цеха)',
        'assembly' => 'Монтаж',
        'act' => 'Акт выполненных работ (бухгалтер)',
        'esf' => 'ЭСФ — электронная счёт-фактура (бухгалтер)',
        'payment_won' => 'Оплата успешно (won)',
    ];

    /**
     * Что делает тип — одной фразой. Владелец не должен гадать, чем
     * `logistics` отличается от `assembly`, выбирая пункт в админке.
     *
     * Ни один тип не обязателен: не назначен — соответствующее правило просто
     * не действует (кроме payment_won, без которого сделки не становятся
     * успешными и не попадают в деньги, ЗП и аналитику).
     */
    public const STAGE_TYPE_HINTS = [
        'contract' => 'Этап заключения договора. Логики не несёт — метка для отчётов.',
        'design' => 'Гейт технолога: сделка ждёт замера и расчёта, технолог видит её в своём списке и подтверждает галочкой.',
        'shop_gate' => 'Ворота в цех: только на этом этапе есть кнопка «В цех», и уйти вперёд сделка может лишь через неё.',
        'logistics' => 'Сюда цех возвращает сделку, закончив заказ. Без этого типа цех не может закрыть заказ.',
        'assembly' => 'Этап монтажа у клиента. Логики не несёт — метка для отчётов.',
        'act' => 'Нужен, только если вы подписываете акт выполненных работ. С этого этапа сделку двигает бухгалтер. Не подписываете акты — тип не назначайте.',
        'esf' => 'Нужен, только если вы выписываете ЭСФ. Переход на него разрешён лишь с этапа «Акт», двигает бухгалтер. Не выписываете — тип не назначайте.',
        'payment_won' => 'Сделка успешна: попадает в доход, ЗП, бонусы и аналитику. Этот тип назначить нужно обязательно.',
    ];

    protected $fillable = [
        'company_id', 'name', 'order', 'color', 'checklist', 'type', 'stage_type',
        'gate_task_title', 'gate_task_role', 'gate_task_days', 'requires_document', 'is_won', 'is_active',
    ];

    protected $casts = [
        'checklist' => 'array',
        'is_won' => 'boolean',
        'requires_document' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * У каждой фирмы СВОЯ воронка: этапы с company_id видны
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

    /**
     * «Акт утверждение» — с него сделку ведёт бухгалтер.
     *
     * Нет этапа с этим типом — значит в воронке его нет, и правила акта не
     * действуют. Раньше здесь подставлялся «второй с конца», и в воронке без
     * акта им оказывалась «Оплата успешно»: правило «на оплату — только с
     * предыдущего этапа» начинало требовать, чтобы сделка УЖЕ была на оплате,
     * и закрыть её успешной становилось нельзя.
     */
    public static function actStage(?int $companyId = null): ?self
    {
        return self::ofType('act', $companyId);
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

        // is_won — тоже признак из админки, поэтому он остаётся запасным.
        // А вот «последний этап воронки» запасным быть не может: последним
        // обычно стоит «Закрытый» / «База», и сделки становились бы там
        // успешными сами собой.
        return $active->firstWhere('stage_type', 'payment_won')
            ?? $active->firstWhere('is_won', true);
    }

    /** «Логистика» — сюда цех возвращает сделку после производства. */
    public static function logisticsStage(?int $companyId = null): ?self
    {
        return self::ofType('logistics', $companyId);
    }

    /**
     * Этап-ворота в цех — на нём доступна кнопка «В цех».
     *
     * Без назначенного типа возвращается null: «третий с конца» ставил кнопку
     * на случайный этап воронки, и убрать её через админку было нельзя.
     */
    public static function workshopGateStage(?int $companyId = null): ?self
    {
        return self::ofType('shop_gate', $companyId);
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
