<?php

/**
 * Словарь журнала аудита: как показывать таблицы, поля и значения по-русски.
 *
 * Лежит в конфиге, а не в контроллере: подписи меняются чаще кода, и сотня
 * строк констант посреди контроллера мешала читать саму логику журнала.
 * Незнакомая таблица или поле показываются как есть — журнал не ломается.
 */
return [

    // Названия таблиц.
    'tables' => [
        'deals' => 'Сделки', 'projects' => 'Заказы цеха', 'tasks' => 'Задачи',
        'invoices' => 'Счета', 'payments' => 'Платежи', 'expenses' => 'Расходы',
        'cash_receipts' => 'Поступления денег', 'debts' => 'Задолженности',
        'payroll_adjustments' => 'Корректировки ЗП', 'dds_entries' => 'ДДС',
        'users' => 'Сотрудники', 'departments' => 'Отделы', 'clients' => 'Клиенты',
        'documents' => 'Документы', 'materials' => 'Склад', 'material_receipts' => 'Приход склада',
        'chats' => 'Чаты', 'chat_messages' => 'Сообщения чата',
        'comments' => 'Комментарии', 'settings' => 'Настройки', 'deal_stages' => 'Этапы сделок',
        'project_stages' => 'Этапы цеха', 'expense_categories' => 'Категории расходов',
        'workshop_screens' => 'ТВ-экраны',
        'work_orders' => 'Наряды бригад', 'work_order_lines' => 'Строки наряда',
        'brigades' => 'Бригады', 'bonus_payouts' => 'Выплаты бонусов',
        'employee_debts' => 'Долги сотрудников', 'employee_debt_payments' => 'Погашение долгов',
        'deal_items' => 'Товары сделки',

    ],

    // Названия полей.
    'fields' => [
        'status' => 'Статус', 'deal_stage_id' => 'Этап', 'project_stage_id' => 'Этап цеха',
        'amount' => 'Сумма', 'budget' => 'Сумма договора', 'payment_method' => 'Способ оплаты',
        'bonus_rate_override' => 'Ручной % бонуса', 'responsible_user_id' => 'Ответственный',
        'assignee_id' => 'Исполнитель', 'department_id' => 'Отдел', 'client_id' => 'Клиент',
        'category_id' => 'Категория', 'material_id' => 'Материал', 'qty' => 'Количество',
        'name' => 'Название', 'title' => 'Заголовок', 'description' => 'Описание', 'note' => 'Заметка',
        'number' => 'Номер', 'bin' => '№ договора', 'address' => 'Адрес',
        'customer_bin' => 'БИН / ИИН заказчика', 'contact_name' => 'Контакт клиента',
        'contact_phone' => 'Телефон клиента',
        'company_name' => 'Заказчик', 'client_name' => 'Товар', 'lot_number' => 'Количество',
        'unit' => 'Ед. изм.', 'source' => 'Источник', 'deadline' => 'Срок',
        'contract_date' => 'Дата договора', 'issue_date' => 'Дата счёта', 'due_date' => 'Срок оплаты',
        'date' => 'Дата', 'closed_at' => 'Закрыта', 'completed_at' => 'Завершена',
        'confirmed_at' => 'Подтверждён', 'started_at' => 'Начат',
        'salary' => 'Оклад', 'phone' => 'Телефон', 'email' => 'Email',
        'birth_date' => 'День рождения', 'hired_at' => 'Дата приёма', 'head_user_id' => 'Руководитель',
        'is_active' => 'Активен', 'is_completed' => 'Завершающий', 'is_won' => 'Успешный этап',
        'type' => 'Тип', 'kind' => 'Вид', 'priority' => 'Приоритет', 'days' => 'Дней',
        'balance' => 'Фактический остаток', 'receivable' => 'Дебиторский', 'bank' => 'Банк',
        'workshop' => 'Цех', 'avatar' => 'Фото', 'language' => 'Язык', 'order' => 'Порядок',
        'color' => 'Цвет', 'pinned_message_id' => 'Закреплённое сообщение', 'expense_id' => 'Расход',
        'price' => 'Цена', 'quantity' => 'Остаток', 'message' => 'Сообщение',
        'file_path' => 'Файл', 'contract_path' => 'Договор (файл)', 'company_id' => 'Фирма',
        'stage_type' => 'Тип этапа',
        // Производство и бонусы: то, что вводят в модальных окнах.
        'brigade_id' => 'Бригада', 'foreman_id' => 'Бригадир', 'user_id' => 'Сотрудник',
        'employee_id' => 'Сотрудник', 'created_by' => 'Внёс', 'confirmed_by' => 'Подтвердил',
        'paid_by' => 'Выдал', 'product' => 'Изделие', 'qty_pcs' => 'Штук', 'qty_m2' => 'Метров²',
        'rate_pcs' => 'Ставка за штуку', 'rate_m2' => 'Ставка за м²', 'role' => 'Роль в наряде',
        'month' => 'За месяц', 'monthly_payment' => 'Платёж в месяц', 'employee_payout' => 'Вид выплаты',
        'sale_amount' => 'Цена продажи', 'markup_pct' => 'Наценка, %', 'bonus_percent' => 'Личный % бонуса',
        'partner_pct' => 'Доля партнёра, %', 'deal_type' => 'Тип сделки', 'project_id' => 'Заказ цеха',
        'deal_id' => 'Сделка', 'invoice_id' => 'Счёт', 'expenseable_id' => 'Запись-хозяин',
        'expenseable_type' => 'Тип хозяина', 'invoiceable_id' => 'Запись-хозяин', 'invoiceable_type' => 'Тип хозяина',
        'payment_date' => 'Дата оплаты', 'method' => 'Способ', 'branch' => 'Филиал', 'area_m2' => 'Площадь, м²',

    ],

    // Значения по полю (статусы, способы оплаты, роли).
    'values' => [
        'status' => [
            'draft' => 'Черновик', 'sent' => 'Выставлен', 'partial' => 'Частично оплачен',
            'paid' => 'Оплачен', 'cancelled' => 'Отменён', 'active' => 'Активна',
            'closed' => 'Закрыта', 'new' => 'Новая', 'todo' => 'К выполнению',
            'in_progress' => 'В работе', 'review' => 'Проверка', 'done' => 'Готово',
            'pending' => 'Ожидает', 'confirmed' => 'Подтверждён', 'completed' => 'Завершён',
        ],
        'payment_method' => ['cash' => 'Наличные', 'bank' => 'Банк'],
        'method' => ['cash' => 'Наличные', 'bank' => 'Банк'],
        'role' => ['worker' => 'Рабочий', 'foreman' => 'Бригадир'],
        'deal_type' => ['production' => 'Своё производство', 'resale' => 'Перепродажа'],
        'employee_payout' => ['bonus' => 'Бонус', 'debt' => 'Выдача долга', 'advance' => 'Аванс', 'salary' => 'Зарплата'],
        'expenseable_type' => ['deal' => 'Сделка', 'project' => 'Заказ цеха'],
        'invoiceable_type' => ['deal' => 'Сделка', 'project' => 'Заказ цеха'],
        'type' => [
            'absence' => 'Отгул', 'sick' => 'Больничный', 'fine' => 'Штраф',
            'advance' => 'Аванс', 'bonus' => 'Премия', 'direct' => 'Прямой',
            'material' => 'Материальный', 'other' => 'Прочий',
            'personal' => 'Личный', 'group' => 'Группа', 'global' => 'Общий',
            'receivable' => 'Дебиторка', 'payable' => 'Кредиторка',
        ],
        'kind' => ['account' => 'Счёт компании', 'debt' => 'Долг', 'workshop' => 'Цех', 'office' => 'Офис'],
        'priority' => ['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий', 'urgent' => 'Срочный'],
        'is_active' => ['1' => 'Да', '0' => 'Нет', 'true' => 'Да', 'false' => 'Нет'],
        'is_completed' => ['1' => 'Да', '0' => 'Нет'],
        'is_won' => ['1' => 'Да', '0' => 'Нет'],

    ],

    // Денежные поля — показываем с разрядами и знаком ₸.
    'money' => [
        'amount', 'budget', 'salary', 'balance', 'receivable', 'price',
        'monthly_payment', 'sale_amount', 'rate_pcs', 'rate_m2', 'unit_price',
    ],
];
