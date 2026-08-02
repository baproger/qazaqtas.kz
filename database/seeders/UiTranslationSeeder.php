<?php

namespace Database\Seeders;

use App\Models\UiTranslation;
use Illuminate\Database\Seeder;

class UiTranslationSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // key, group, ru, kk
            ['nav.dashboard', 'nav', 'Дашборд', 'Басқару тақтасы'],
            ['nav.analytics', 'nav', 'Аналитика', 'Аналитика'],
            ['nav.deals', 'nav', 'Сделки', 'Мәмілелер'],
            ['nav.overdue', 'nav', 'Просроченные', 'Мерзімі өткен'],
            ['nav.workshop', 'nav', 'Цех', 'Цех'],
            ['nav.chat', 'nav', 'Чат', 'Чат'],
            ['nav.profile', 'nav', 'Профиль', 'Профиль'],
            ['nav.finance', 'nav', 'Финансы', 'Қаржы'],
            ['nav.payroll', 'nav', 'Зарплата', 'Жалақы'],
            ['nav.audit', 'nav', 'Аудит', 'Аудит'],
            ['nav.departments', 'nav', 'Отделы', 'Бөлімдер'],
            ['nav.users', 'nav', 'Сотрудники', 'Қызметкерлер'],
            ['nav.settings', 'nav', 'Настройки', 'Баптаулар'],
            ['nav.translations', 'nav', 'Переводы', 'Аудармалар'],

            ['header.title', 'header', 'Панель управления', 'Басқару панелі'],
            ['header.notifications', 'header', 'Уведомления', 'Хабарламалар'],
            ['header.read_all', 'header', 'Прочитать все', 'Барлығын оқу'],
            ['header.no_notifications', 'header', 'Нет уведомлений', 'Хабарлама жоқ'],
            ['header.profile', 'header', 'Профиль', 'Профиль'],
            ['header.logout', 'header', 'Выйти', 'Шығу'],
            ['header.collapse', 'header', 'Свернуть', 'Жию'],

            ['common.save', 'common', 'Сохранить', 'Сақтау'],
            ['common.cancel', 'common', 'Отмена', 'Болдырмау'],
            ['common.delete', 'common', 'Удалить', 'Жою'],
            ['common.create', 'common', 'Создать', 'Жасау'],
            ['common.edit', 'common', 'Изменить', 'Өзгерту'],
            ['common.search', 'common', 'Поиск', 'Іздеу'],
            ['common.confirm', 'common', 'Подтвердить', 'Растау'],
            ['common.saved', 'common', 'Сохранено', 'Сақталды'],
            ['common.loading', 'common', 'Загрузка…', 'Жүктелуде…'],
            ['common.no_data', 'common', 'Нет данных', 'Деректер жоқ'],

            // Page headers
            ['page.dashboard', 'page', 'Дашборд', 'Басқару тақтасы'],
            ['page.analytics', 'page', 'Аналитика', 'Аналитика'],
            ['page.deals', 'page', 'Сделки', 'Мәмілелер'],
            ['page.overdue', 'page', 'Просроченные сделки', 'Мерзімі өткен мәмілелер'],
            ['page.workshop', 'page', 'Цех', 'Цех'],
            ['page.chat', 'page', 'Чат', 'Чат'],
            ['page.profile', 'page', 'Профиль', 'Профиль'],
            ['page.finance', 'page', 'Финансы', 'Қаржы'],
            ['page.payroll', 'page', 'Зарплата и бонусы', 'Жалақы және бонустар'],
            ['page.audit', 'page', 'Журнал аудита', 'Аудит журналы'],
            ['page.departments', 'page', 'Отделы', 'Бөлімдер'],
            ['page.clients', 'page', 'Контрагенты', 'Контрагенттер'],
            ['page.users', 'page', 'Сотрудники', 'Қызметкерлер'],
            ['page.settings', 'page', 'Настройки системы', 'Жүйе баптаулары'],
            ['page.settings_stages', 'page', 'Настройки · Этапы', 'Баптаулар · Кезеңдер'],
            ['page.settings_fields', 'page', 'Настройки · Дополнительные поля', 'Баптаулар · Қосымша өрістер'],
            ['page.translations', 'page', 'Переводы интерфейса', 'Интерфейс аудармалары'],
            ['deal.overdue_badge', 'common', 'Просрочено', 'Мерзімі өтті'],
        ];

        foreach ($rows as [$key, $group, $ru, $kk]) {
            UiTranslation::updateOrCreate(['key' => $key], ['group' => $group, 'ru' => $ru, 'kk' => $kk]);
        }
    }
}
