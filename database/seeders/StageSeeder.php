<?php

namespace Database\Seeders;

use App\Models\DealStage;
use App\Models\ProjectStage;
use Illuminate\Database\Seeder;

class StageSeeder extends Seeder
{
    public function run(): void
    {
        $dealStages = [
            ['name' => 'Новая',        'kk' => 'Жаңа',        'color' => '#3B82F6', 'is_won' => false],
            ['name' => 'Квалификация', 'kk' => 'Біліктілік',  'color' => '#6366F1', 'is_won' => false],
            ['name' => 'Предложение',  'kk' => 'Ұсыныс',      'color' => '#F59E0B', 'is_won' => false],
            ['name' => 'Переговоры',   'kk' => 'Келіссөздер', 'color' => '#8B5CF6', 'is_won' => false],
            ['name' => 'Договор',      'kk' => 'Шарт',        'color' => '#EC4899', 'is_won' => false],
            ['name' => 'Оплачено',     'kk' => 'Төленді',     'color' => '#10B981', 'is_won' => true, 'stage_type' => 'payment_won'],
        ];

        foreach ($dealStages as $i => $s) {
            $stage = DealStage::updateOrCreate(
                ['name' => $s['name'], 'type' => 'sale'],
                ['order' => $i + 1, 'color' => $s['color'], 'is_won' => $s['is_won'], 'is_active' => true, 'checklist' => [], 'stage_type' => $s['stage_type'] ?? null]
            );
            $stage->translations()->updateOrCreate(['locale' => 'ru'], ['name' => $s['name']]);
            $stage->translations()->updateOrCreate(['locale' => 'kk'], ['name' => $s['kk']]);
        }

        $workshopStages = [
            ['name' => 'Кесу',           'kk' => 'Кесу',           'color' => '#3B82F6', 'done' => false],
            ['name' => 'Жабыстыру',      'kk' => 'Жабыстыру',      'color' => '#6366F1', 'done' => false],
            ['name' => 'Тесу',           'kk' => 'Тесу',           'color' => '#F59E0B', 'done' => false],
            ['name' => 'Упаковка',       'kk' => 'Қаптама',        'color' => '#8B5CF6', 'done' => false],
            ['name' => 'Отправка',       'kk' => 'Жіберу',         'color' => '#EC4899', 'done' => false],
            ['name' => 'Акт утверждение','kk' => 'Актіні бекіту',  'color' => '#14B8A6', 'done' => false],
            ['name' => 'Оплата',         'kk' => 'Төлем',          'color' => '#10B981', 'done' => true],
        ];

        foreach ($workshopStages as $i => $s) {
            $stage = ProjectStage::updateOrCreate(
                ['name' => $s['name'], 'type' => 'project'],
                ['order' => $i + 1, 'color' => $s['color'], 'is_completed' => $s['done'], 'is_active' => true, 'checklist' => []]
            );
            $stage->translations()->updateOrCreate(['locale' => 'ru'], ['name' => $s['name']]);
            $stage->translations()->updateOrCreate(['locale' => 'kk'], ['name' => $s['kk']]);
        }
    }
}
