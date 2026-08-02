<?php

namespace Database\Seeders;

use App\Models\DealStage;
use App\Models\ProjectStage;
use Illuminate\Database\Seeder;

/**
 * Стартовые воронки QAZAQ TAS (производство МАФ и тротуарной плитки из
 * мраморного композита). Дальше они правятся в Настройки → Этапы, поэтому
 * логика держится на stage_type, а не на названиях.
 *
 * Сделка: Заявка → Замер и расчёт (гейт технолога) → Договор → Закуп сырья
 * (гейт снабженца, отсюда «В цех») → Логистика (возврат из цеха) → Монтаж →
 * Акт → ЭСФ → Оплата успешно.
 *
 * Цех: Формовка → Шлифовка → Упаковка → Отправка (завершающий: «Готово ✓»
 * возвращает сделку на «Логистику»).
 *
 * Этапы сидятся общими (company_id = null): при одной фирме это и есть её
 * воронка. Если фирм станет больше — в Настройки → Этапы у каждой заводится
 * своя, и общие перестают показываться.
 */
class StageSeeder extends Seeder
{
    public function run(): void
    {
        $dealStages = [
            ['name' => 'Заявка', 'kk' => 'Өтінім', 'color' => '#3B82F6'],
            ['name' => 'Замер и расчёт', 'kk' => 'Өлшеу және есептеу', 'color' => '#6366F1', 'stage_type' => 'design',
                'gate' => ['Сделать замер и расчёт изделия', 'designer', 3]],
            ['name' => 'Договор', 'kk' => 'Шарт', 'color' => '#8B5CF6', 'stage_type' => 'contract'],
            ['name' => 'Закуп сырья', 'kk' => 'Шикізат сатып алу', 'color' => '#F59E0B', 'stage_type' => 'shop_gate',
                'gate' => ['Закупить сырьё и оснастку', 'supplier', 5]],
            ['name' => 'Логистика', 'kk' => 'Логистика', 'color' => '#0EA5E9', 'stage_type' => 'logistics'],
            ['name' => 'Монтаж', 'kk' => 'Монтаж', 'color' => '#14B8A6', 'stage_type' => 'assembly'],
            ['name' => 'Акт утверждение', 'kk' => 'Актіні бекіту', 'color' => '#EC4899', 'stage_type' => 'act',
                'gate' => ['Подписать акт выполненных работ', 'financist', 3]],
            ['name' => 'ЭСФ', 'kk' => 'ЭШФ', 'color' => '#A855F7', 'stage_type' => 'esf'],
            ['name' => 'Оплата успешно', 'kk' => 'Төлем сәтті', 'color' => '#10B981', 'is_won' => true, 'stage_type' => 'payment_won'],
        ];

        foreach ($dealStages as $i => $s) {
            [$gateTitle, $gateRole, $gateDays] = $s['gate'] ?? [null, null, 0];

            $stage = DealStage::updateOrCreate(
                ['name' => $s['name'], 'type' => 'sale'],
                [
                    'order' => $i + 1,
                    'color' => $s['color'],
                    'is_won' => $s['is_won'] ?? false,
                    'is_active' => true,
                    'checklist' => [],
                    'stage_type' => $s['stage_type'] ?? null,
                    'gate_task_title' => $gateTitle,
                    'gate_task_role' => $gateRole,
                    'gate_task_days' => $gateDays,
                ]
            );
            $stage->translations()->updateOrCreate(['locale' => 'ru'], ['name' => $s['name']]);
            $stage->translations()->updateOrCreate(['locale' => 'kk'], ['name' => $s['kk']]);
        }

        // Производство одно (workshop = null) — выбор цеха в интерфейсе не
        // появляется. Второй участок добавляется в Настройки → Этапы.
        $workshopStages = [
            ['name' => 'Формовка', 'kk' => 'Қалыптау', 'color' => '#3B82F6', 'done' => false],
            ['name' => 'Шлифовка', 'kk' => 'Тегістеу', 'color' => '#6366F1', 'done' => false],
            ['name' => 'Упаковка', 'kk' => 'Қаптама', 'color' => '#F59E0B', 'done' => false],
            ['name' => 'Отправка', 'kk' => 'Жіберу', 'color' => '#10B981', 'done' => true],
        ];

        foreach ($workshopStages as $i => $s) {
            $stage = ProjectStage::updateOrCreate(
                ['name' => $s['name'], 'type' => 'project'],
                ['order' => $i + 1, 'color' => $s['color'], 'is_completed' => $s['done'], 'is_active' => true, 'checklist' => [], 'workshop' => null]
            );
            $stage->translations()->updateOrCreate(['locale' => 'ru'], ['name' => $s['name']]);
            $stage->translations()->updateOrCreate(['locale' => 'kk'], ['name' => $s['kk']]);
        }
    }
}
