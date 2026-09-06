<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Разбор вопроса на фильтры: период, человек, город, «топ-N».
 *
 * Помощник без ИИ отвечает выборками, а выборке нужны рамки: «сделки
 * Ермана за месяц по Шымкенту» — это три условия в одном предложении.
 * Разбираем их отдельно от темы вопроса, чтобы любой раздел (сделки,
 * задачи, деньги) мог применить одни и те же рамки.
 */
class QuestionScope
{
    public ?Carbon $from = null;

    public ?Carbon $to = null;

    /** Подпись периода для заголовка ответа: «за месяц», «за вчера». */
    public ?string $periodLabel = null;

    public ?User $user = null;

    public ?string $city = null;

    public int $limit = 10;

    public static function parse(string $question): self
    {
        $q = mb_strtolower($question);
        $scope = new self;

        $scope->period($q);
        $scope->person($q);
        $scope->place($q);
        $scope->topN($q);

        return $scope;
    }

    /** Есть ли хоть один фильтр — от этого зависит заголовок ответа. */
    public function any(): bool
    {
        return $this->from !== null || $this->user !== null || $this->city !== null;
    }

    /** Подпись всех рамок: «за месяц · Ерман · Шымкент». */
    public function label(): string
    {
        return implode(' · ', array_filter([
            $this->periodLabel,
            $this->user?->name,
            $this->city,
        ]));
    }

    private function period(string $q): void
    {
        $now = Carbon::now();

        [$from, $to, $label] = match (true) {
            str_contains($q, 'сегодня') => [$now->copy()->startOfDay(), $now, 'за сегодня'],
            str_contains($q, 'вчера') => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
                'за вчера',
            ],
            // «прошлый месяц» проверяем раньше «месяца» — иначе съест его.
            str_contains($q, 'прошл') && str_contains($q, 'месяц') => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
                'за прошлый месяц',
            ],
            str_contains($q, 'недел') => [$now->copy()->startOfWeek(), $now, 'за неделю'],
            str_contains($q, 'месяц') => [$now->copy()->startOfMonth(), $now, 'за месяц'],
            str_contains($q, 'квартал') => [$now->copy()->startOfQuarter(), $now, 'за квартал'],
            str_contains($q, 'год') => [$now->copy()->startOfYear(), $now, 'за год'],
            default => [null, null, null],
        };

        $this->from = $from;
        $this->to = $to;
        $this->periodLabel = $label;
    }

    /**
     * Человек по имени. Ищем слова от 4 букв: короткие имена рискуют
     * совпасть со случайным словом вопроса, а «Ерман» найдётся и в
     * «у Ермана» — падеж меняет окончание, а не корень.
     */
    private function person(string $q): void
    {
        foreach (User::query()->where('is_active', true)->get(['id', 'name']) as $user) {
            foreach (preg_split('/\s+/u', mb_strtolower($user->name)) as $word) {
                if (mb_strlen($word) >= 4 && str_contains($q, $word)) {
                    $this->user = $user;

                    return;
                }
            }
        }
    }

    private function place(string $q): void
    {
        foreach (['шымкент' => 'Шымкент', 'алмат' => 'Алматы', 'тараз' => 'Тараз', 'астан' => 'Астана'] as $needle => $city) {
            if (str_contains($q, $needle)) {
                $this->city = $city;

                return;
            }
        }
    }

    private function topN(string $q): void
    {
        if (preg_match('/(?:топ|лучш|крупн)\D{0,12}(\d{1,2})/u', $q, $m)
            || preg_match('/(\d{1,2})\s*(?:лучш|крупн|самы)/u', $q, $m)) {
            $this->limit = max(3, min(30, (int) $m[1]));
        }
    }
}
