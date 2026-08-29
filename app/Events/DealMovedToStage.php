<?php

namespace App\Events;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Сделка перешла на этап. Единственная точка, где логика переходов
 * «говорит» роботам; сама она ничего о них не знает.
 */
class DealMovedToStage
{
    use Dispatchable;

    public string $transitionId;

    public function __construct(
        public Deal $deal,
        public ?DealStage $from,
        public DealStage $to,
        public ?User $user = null,
        ?string $transitionId = null,
    ) {
        $this->transitionId = $transitionId ?? (string) Str::uuid();
    }
}
