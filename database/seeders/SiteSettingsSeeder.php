<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\SiteContent;
use Illuminate\Database\Seeder;

/**
 * Раскладывает значения по умолчанию в настройки, чтобы владелец открыл
 * «Настройки → Сайт» и увидел готовый текст, а не пустые поля.
 *
 * Уже заполненное не трогаем: сидер безопасно гонять повторно.
 */
class SiteSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $written = 0;

        foreach (SiteContent::DEFAULTS as $key => $value) {
            // Пустые заготовки (например, Instagram) не записываем: пустое
            // значение и так означает «канала нет».
            if ($value === '' || $value === null) {
                continue;
            }
            if (Setting::query()->where('key', $key)->exists()) {
                continue;
            }

            Setting::set($key, $value);
            $written++;
        }

        $this->command?->info("Настройки сайта: записано значений — {$written}.");
    }
}
