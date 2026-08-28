<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use Auditable;
    use HasFactory, SoftDeletes;

    /** Ед. изм. для поля «Количество» (колонка lot_number). */
    public const UNITS = ['штук', 'м²', 'м³', 'метр погонный', 'комплект', 'паллета', 'тонна', 'кг', 'мешок', 'литр', 'рулон', 'работа'];

    /** Источник, откуда пришёл заказ (канал продаж). */
    public const SOURCES = ['Сайт', 'Instagram', 'WhatsApp', 'Входящий звонок', 'Рекомендация', 'Повторный клиент', 'Выставка', 'Дилер / партнёр', 'Госзакуп', 'Другое'];

    protected $fillable = [
        'company_id', 'branch', 'number', 'name', 'client_name', 'product_id', 'company_name', 'address', 'bin', 'customer_bin', 'contact_name', 'contact_phone', 'contract_date', 'lot_number', 'unit', 'area_m2', 'source', 'client_id', 'responsible_user_id', 'foreman_id', 'department_id',
        'deal_type', 'deal_stage_id', 'budget', 'partner_pct', 'bonus_rate_override', 'deadline', 'description', 'note', 'status', 'closed_at',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'deadline' => 'date',
        'contract_date' => 'date',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // История этапов (deal_stage_logs): создание открывает таймер первого
        // этапа; смена этапа закрывает старый и открывает новый; уход в цех /
        // отмена — закрывает; возврат в работу — открывает снова.
        static::created(function (Deal $deal) {
            if ($deal->deal_stage_id) {
                DealStageLog::open($deal);
            }
        });
        static::updated(function (Deal $deal) {
            if ($deal->wasChanged('deal_stage_id')) {
                DealStageLog::closeOpen($deal);
                if ($deal->deal_stage_id && $deal->status !== 'cancelled') {
                    DealStageLog::open($deal);
                }
            } elseif ($deal->wasChanged('status')) {
                if (in_array($deal->status, ['closed', 'cancelled'], true)) {
                    DealStageLog::closeOpen($deal);
                } elseif ($deal->status === 'active' && $deal->deal_stage_id
                    && ! DealStageLog::where('deal_id', $deal->id)->whereNull('left_at')->exists()) {
                    DealStageLog::open($deal);
                }
            }
        });

        // Удалили сделку — её заказ в цехе не должен висеть «в работе»:
        // отменяем (иначе канбан цеха и просроченные показывают заказ-сироту).
        static::deleted(function (Deal $deal) {
            DealStageLog::closeOpen($deal);
            Project::where('deal_id', $deal->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->update(['status' => 'cancelled']);

            // Освобождаем номер: у deals.number unique-индекс учитывает и
            // удалённые строки — без переименования новая сделка не смогла бы
            // получить этот номер, а нумерация никогда не началась бы заново.
            if ($deal->number && ! str_contains($deal->number, '#del')) {
                Deal::withTrashed()->whereKey($deal->id)
                    ->update(['number' => $deal->number.'#del'.$deal->id]);
            }
        });
    }

    /**
     * Owning firm — not to be confused with company_name (the client's company).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * Бригадир, ведущий сделку в цехе. Отдельно от ответственного менеджера:
     * менеджер отвечает за клиента и деньги, бригадир — за работу.
     */
    public function foreman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'foreman_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Позиции сделки: товар, количество, цена. Сумма сделки = их сумма. */
    public function items(): HasMany
    {
        return $this->hasMany(DealItem::class)->orderBy('sort')->orderBy('id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'deal_stage_id');
    }

    public function project(): HasOne
    {
        // Latest workshop run for this deal (a deal may go through the workshop
        // more than once over its lifetime; the newest one reflects current state).
        return $this->hasOne(Project::class)->latestOfMany();
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function invoices(): MorphMany
    {
        return $this->morphMany(Invoice::class, 'invoiceable');
    }

    public function expenses(): MorphMany
    {
        return $this->morphMany(Expense::class, 'expenseable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Successful deals = reached the "won" stage («Оплата успешно»), excluding cancelled.
     * Money counts as fact only here (payroll/analytics/dashboard): a deal in the
     * workshop or waiting on «Акт утверждение» is NOT counted until it hits «Оплата».
     */
    public function scopeWon($query)
    {
        return $query->where('status', '!=', 'cancelled')
            ->whereHas('stage', fn ($s) => $s->where('is_won', true));
    }

    /**
     * Restrict to the firm currently selected in the session.
     * No-op when no company is selected (e.g. console commands, tests).
     */
    public function scopeForCurrentCompany($query)
    {
        return $query->when(CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c));
    }
}
