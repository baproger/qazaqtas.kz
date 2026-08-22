<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Deal;
use App\Models\DealStage;
use Illuminate\Http\Request;

/**
 * Общие правила доступа к сделке: заморозка после «Акта» и гейт-задача этапа.
 *
 * Живут в одном месте, потому что их спрашивают три контроллера — карточка
 * сделки, переходы по этапам и смена ответственного. Разойдись копии, и
 * сделку, закрытую для менеджера в одном месте, он поменял бы в другом.
 */
trait DealGuards
{
    /**
     * После «Акт утверждение» сделку изменяет только бухгалтер/админ:
     * менеджеру (и директору) недоступны редактирование, смена ответственного
     * и удаление сделки на этапах АКТ / ЭСФ / Оплата успешно.
     */
    private function assertNotFrozen(Request $request, Deal $deal): void
    {
        if ($request->user()->hasAnyRole(['admin', 'financist'])) {
            return;
        }
        $companyId = $deal->company_id ? (int) $deal->company_id : null;
        $frozenIds = collect([
            DealStage::actStage($companyId)?->id,
            DealStage::esfStage($companyId)?->id,
            DealStage::wonStage($companyId)?->id,
        ])->filter();

        abort_if(
            $frozenIds->contains($deal->deal_stage_id),
            403,
            'После «Акт утверждение» сделку изменяет только бухгалтер или админ.'
        );
    }

    /** Текущий этап сделки, если на нём настроен гейт (или null). */
    /** Подписи ролей гейт-задач (для сообщений и карточки сделки). */
    private const GATE_ROLE_LABELS = ['financist' => 'бухгалтер', 'designer' => 'технолог', 'supplier' => 'снабженец', 'manager' => 'менеджер', 'director' => 'директор', 'admin' => 'админ'];

    private static function gateStage(Deal $deal): ?DealStage
    {
        $stage = $deal->stage ?? DealStage::find($deal->deal_stage_id);

        return $stage && $stage->hasGate() ? $stage : null;
    }
}
