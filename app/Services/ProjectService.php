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

        $companyId = $deal->company_id ? (int) $deal->company_id : null;
        $returnStage = \App\Models\DealStage::logisticsStage($companyId) ?? \App\Models\DealStage::actStage($companyId);
        if (! $returnStage) {
            return [false, 'Не найден этап «Логистика».'];
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

        // Заказ попадает в цех СВОЕЙ фирмы; если цехов несколько (
        // «Ағаш цех») — берётся воронка выбранного цеха.
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
