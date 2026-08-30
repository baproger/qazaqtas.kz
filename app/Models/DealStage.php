<?php

namespace App\Models;

use App\Support\RoleTraits;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class DealStage extends Model
{
    use HasFactory;

    /**
     * Системные типы этапов: логика (гейты, права, won, возврат из цеха)
     * держится на stage_type, а НЕ на названии — этапы можно свободно
     * переименовывать. Типы с логикой: shop_gate (кнопка «В цех»), logistics
     * (возврат из цеха), act / esf (ведёт бухгалтер), payment_won (won).
     */
    /**
     * Только те метки, на которые ОПИРАЕТСЯ КОД. Всё, что можно собрать из
     * условий и роботов (кто двигает, гейт, откуда можно прийти), — метка не
     * нужна: contract / design / assembly убраны 29.08.2026, их правила
     * перенесены в `rules`.
     */
    public const STAGE_TYPES = [
        'shop_gate' => 'Ворота в цех',
        'logistics' => 'Возврат из цеха',
        'payment_won' => 'Успешная сделка',
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
        'shop_gate' => 'Только на этом этапе есть кнопка «В цех», и вперёд сделка уходит лишь через неё. Нет этапа с этой ролью — заказ в цех не создать.',
        'logistics' => 'Сюда цех возвращает сделку, закончив заказ. Нет этапа с этой ролью — цех не сможет закрыть заказ.',
        'payment_won' => 'Сделка успешна: попадает в доход, ЗП, бонусы и аналитику. Этот тип назначить нужно обязательно.',
    ];

    protected $fillable = [
        'company_id', 'name', 'order', 'color', 'checklist', 'type', 'stage_type',
        'gate_task_title', 'gate_task_role', 'gate_task_days', 'requires_document', 'rules', 'is_won', 'is_closing', 'ignores_deadline', 'is_active',
    ];

    /**
     * Пустой набор правил — форма конструктора. Ключи:
     *   leave_roles   — кто может увести сделку С этого этапа вперёд ([] = все, кто ведёт сделку);
     *   enter_roles   — кто может перевести сделку НА этот этап ([] = все);
     *   extra_movers  — роли, которым разрешено «Далее →» на этом этапе, даже без права править сделку;
     *   from_stages   — id этапов, с которых сюда можно прийти ([] = с любого);
     *   require       — что должно быть выполнено, чтобы уйти вперёд;
     */
    public const EMPTY_RULES = [
        'leave_roles' => [],
        'enter_roles' => [],
        'extra_movers' => [],
        'from_stages' => [],
        'require' => ['invoice' => false, 'payment' => 'none', 'items_done' => false],
        // Гейт-задача закрыта → сделка сама уходит на следующий этап.
        'advance_on_gate' => false,
    ];

    protected $casts = [
        'checklist' => 'array',
        'rules' => 'array',
        'is_won' => 'boolean',
        'is_closing' => 'boolean',
        'ignores_deadline' => 'boolean',
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
    public static function funnel(?int $companyId = null): Collection
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
     * Этапы «финальной проверки» (флаг is_closing): сделка почти закрыта —
     * «на подходе» в отчётах и ЗП, карточку правит только бухгалтер/админ.
     * Нет таких этапов — блока «на подходе» нет.
     *
     * @return Collection<int, int>
     */
    public static function closingIds(?int $companyId = null): Collection
    {
        return self::funnel($companyId)->where('is_closing', true)->pluck('id');
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

    /**
     * Действующие правила этапа.
     *
     * Сохранённые владельцем — как есть. Не сохранял (rules = NULL) — выводим
     * из системного типа ровно то, что раньше было зашито в код: акт/ЭСФ/оплату
     * двигает бухгалтер, на ЭСФ — только с акта, на оплату — с ЭСФ или акта,
     * на замере технолог может нажать «Далее».
     *
     * @return array<string, mixed>
     */
    public function effectiveRules(): array
    {
        $rules = self::EMPTY_RULES;
        if (is_array($this->rules)) {
            foreach (self::EMPTY_RULES as $k => $default) {
                $rules[$k] = is_array($default) && ! array_is_list($default)
                    ? array_replace($default, (array) ($this->rules[$k] ?? []))
                    : ($this->rules[$k] ?? $default);
            }

            return $rules;
        }

        return array_replace($rules, self::rulesFromType($this->stage_type, $this->company_id ? (int) $this->company_id : null));
    }

    /**
     * Правила, которые раньше были зашиты в код за системным типом.
     *
     * @return array<string, mixed>
     */
    public static function rulesFromType(?string $type, ?int $companyId): array
    {
        // Метки act/esf/design сняты 29.08.2026 (их правила лежат в rules);
        // ветки оставлены для старых записей, у которых rules ещё NULL.
        return match ($type) {
            'act' => ['leave_roles' => ['financist']],
            'esf' => ['leave_roles' => ['financist'], 'enter_roles' => ['financist'],
                'from_stages' => array_values(array_filter([self::ofType('act', $companyId)?->id]))],
            'payment_won' => ['leave_roles' => ['financist'], 'enter_roles' => ['financist'],
                'from_stages' => array_values(array_filter([(self::ofType('esf', $companyId) ?? self::ofType('act', $companyId))?->id]))],
            'design' => ['extra_movers' => ['designer']],
            default => [],
        };
    }

    /**
     * Кому ставится гейт-задача: не роли целиком, а конкретным людям.
     *
     * Особые значения — «ответственный за сделку» (по умолчанию),
     * «руководитель отдела ответственного», «бригадир сделки»; любое другое —
     * код роли, и тогда задача идёт всем активным с этой ролью (владелец
     * выбирает это осознанно).
     */
    public const GATE_SPECIAL = [
        'responsible' => 'Ответственный за сделку',
        'department_head' => 'Руководитель отдела ответственного',
        'foreman' => 'Бригадир сделки',
    ];

    /** @return Collection<int, User> */
    public function gateAssignees(Deal $deal): Collection
    {
        $who = $this->gate_task_role ?: 'responsible';
        $deal->loadMissing('responsible');

        $people = match ($who) {
            'responsible' => collect([$deal->responsible]),
            'department_head' => collect([$deal->responsible?->department?->head ?? $deal->responsible]),
            'foreman' => collect([$deal->foreman_id ? User::find($deal->foreman_id) : null]),
            default => RoleTraits::users($who)->where('is_active', true)->get(),
        };

        return $people->filter(fn ($u) => $u && $u->is_active)->unique('id')->values();
    }

    /** Может ли человек поставить галочку гейта этой сделки. */
    public function gateAllowedFor(User $user, Deal $deal): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        $who = $this->gate_task_role ?: 'responsible';

        return array_key_exists($who, self::GATE_SPECIAL)
            ? $this->gateAssignees($deal)->contains('id', $user->id)
            : $user->hasRole($who);
    }

    /** Подпись «кому» — особое значение или название роли из БД. */
    public function gateRoleLabel(): string
    {
        $who = $this->gate_task_role ?: 'responsible';

        return self::GATE_SPECIAL[$who] ?? (Role::where('name', $who)->first()?->title() ?? $who);
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
