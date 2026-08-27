<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Project;
use App\Models\ProjectStage;

class ProjectService
{
    public function __construct(private ProjectNumberService $numbers) {}

    /**
     * «Готово»: завершить заказ цеха и вернуть сделку на «Логистику»
     * (или «Акт», если этапа логистики нет). Общая логика ERP и ТВ-экрана.
     *
     * @return array{0: bool, 1: string}  [успех, сообщение]
     */
    public function completeAndReturnDeal(Project $project): array
    {
        $deal = $project->deal;
        if (! $deal) {
            return [false, 'У заказа нет исходной сделки.'];
        }

        // Незакрытые позиции держат заказ в цехе. Отметку «товар закончен»
        // ставит бригадир — только он знает, что работа по этому товару
        // действительно кончилась. Уедь машина раньше, и на объект приехала
        // бы половина заказа, а по бумагам он был бы сдан.
        $unfinished = $deal->items()->whereNull('finished_at')->pluck('name');
        if ($unfinished->isNotEmpty()) {
            return [false, 'Не закончены товары: '.$unfinished->implode(', ')
                .'. Отметьте их в карточке цеха — тогда заказ уйдёт на «Логистику».'];
        }

        $companyId = $deal->company_id ? (int) $deal->company_id : null;
        // Куда вернуть сделку, решает системный тип «Логистика (возврат из
        // цеха)». Подставлять этап по позиции нельзя: в воронке без логистики
        // им оказывалась «Оплата успешно», и заказ из цеха закрывал сделку
        // успешной — с деньгами, ЗП и аналитикой.
        $returnStage = \App\Models\DealStage::logisticsStage($companyId)
            ?? \App\Models\DealStage::actStage($companyId);
        if (! $returnStage) {
            return [false, 'Не задан этап возврата из цеха: назначьте системный тип «Логистика (возврат из цеха)» нужному этапу в Настройки → Этапы.'];
        }

        $deal->update(['deal_stage_id' => $returnStage->id, 'status' => 'active', 'closed_at' => null]);
        $project->update(['status' => 'completed', 'completed_at' => now()]);

        return [true, 'Заказ завершён — сделка отправлена на «'.$returnStage->name.'».'];
    }

    /**
     * Create an execution Project from a Deal. Idempotent for an ACTIVE run
     * (returns it), but a completed prior run starts a fresh workshop cycle —
     * otherwise re-sending would close the deal with no active project (lost).
     */
    public function createFromDeal(Deal $deal, ?string $workshop = null): Project
    {
        if ($deal->project && $deal->project->status !== 'completed') {
            return $deal->project;
        }

        // Заказ попадает в цех СВОЕЙ фирмы; если цехов несколько —
        // берётся воронка выбранного цеха, иначе единственная.
        $companyId = $deal->company_id ? (int) $deal->company_id : null;
        $available = ProjectStage::workshopsFor($companyId);
        if (! in_array($workshop, $available, true)) {
            $workshop = count($available) === 1 ? $available[0] : null;
        }
        $firstStage = ProjectStage::funnel($companyId, $workshop)->first();

        return Project::create([
            'workshop' => $workshop,
            'number' => $this->numbers->generate(),
            'name' => $deal->company_name ?: $deal->name,
            'deal_id' => $deal->id,
            'client_id' => $deal->client_id,
            'responsible_user_id' => $deal->responsible_user_id,
            'department_id' => $deal->department_id,
            'project_stage_id' => $firstStage?->id,
            'budget' => $deal->budget,
            'deadline' => $deal->deadline,
            'description' => $deal->description,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }
}
