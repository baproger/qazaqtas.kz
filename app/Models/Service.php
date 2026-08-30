<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasSeoMeta;
use App\Models\Concerns\HasTranslations;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Услуга партнёра. Публикуется только после модерации (status = approved);
 * партнёр видит и правит строго свои (ServicePolicy). Правка одобренной
 * услуги возвращает её на модерацию.
 */
class Service extends Model
{
    use Auditable, HasSeoMeta, HasTranslations;

    protected static function translatable(): array
    {
        return ['title', 'description'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ServiceTranslation::class);
    }

    public const STATUSES = ['pending' => 'На проверке', 'approved' => 'Опубликована', 'rejected' => 'Отклонена'];

    protected $fillable = [
        'partner_id', 'category_id', 'title', 'slug', 'description', 'price',
        'contact_phone', 'contact_name', 'city', 'photo', 'photo_webp', 'photo_thumb',
        'status', 'rejection_reason', 'moderated_at', 'moderated_by',
    ];

    protected $casts = ['price' => 'decimal:2', 'moderated_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (Service $s) {
            if ($s->slug === null || $s->isDirty('title')) {
                $s->slug = Seo::slug($s->title, self::class, $s->id);
            }
        });
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', 'approved');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /** @return array<string, mixed> карточка для витрины: тексты на языке страницы */
    public function toCard(): array
    {
        return [
            'id' => $this->id, 'title' => $this->tr('title'), 'slug' => $this->slug,
            'description' => Seo::text($this->tr('description'), 140),
            'price' => $this->price !== null ? (float) $this->price : null,
            'city' => $this->city,
            'category' => $this->category ? ['name' => $this->category->tr('name'), 'slug' => $this->category->slug] : null,
            'photo' => $this->photo, 'photo_webp' => $this->photo_webp, 'thumb' => $this->photo_thumb,
        ];
    }
}
