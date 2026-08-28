<?php

namespace Database\Seeders;

use App\Models\Brigade;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealItem;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\FinanceService;
use App\Services\ProductionBonusService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Демо-данные: показать, как система считает деньги людям.
 *
 * Живой пример вместо описания: отдел продаж со сделкой и перепродажей,
 * бригада со сменными нарядами — и то, как из этого складывается бонус на
 * странице «Бонусы».
 *
 * Всё демо помечено: пользователи на @demo.kz, сделки номером DEMO-*,
 * бригада «Демо-бригада». Повторный запуск сначала стирает прошлое демо,
 * поэтому данных не удваивается, а рабочие записи не трогаются.
 *
 *   php artisan db:seed --class=DemoSeeder     # завести
 *   php artisan demo:clear                     # убрать
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'demo12345';

    public function run(): void
    {
        $this->clear();

        $companyId = Company::query()->value('id');
        $stageWon = DealStage::where('is_won', true)->value('id') ?? DealStage::query()->value('id');
        $stageShop = DealStage::where('stage_type', 'shop_gate')->value('id') ?? $stageWon;

        // ── Люди ──────────────────────────────────────────────────────────
        $foreman = $this->user('brigadir@demo.kz', 'Асхат Бекболат (бригадир)', 'foreman', $companyId, 250000);
        $worker1 = $this->user('rabochiy1@demo.kz', 'Ерлан Сакенов (рабочий)', 'employee', $companyId, 180000);
        $worker2 = $this->user('rabochiy2@demo.kz', 'Дамир Токтаров (рабочий)', 'employee', $companyId, 180000);
        $sales = $this->user('prodazhi@demo.kz', 'Айгуль Нурланова (продажи)', 'manager', $companyId, 200000);
        $callCenter = $this->user('callcentr@demo.kz', 'Салтанат Ерсин (колл-центр)', 'manager', $companyId, 180000);

        // ── Бригада и наряды ──────────────────────────────────────────────
        $brigade = Brigade::create([
            'company_id' => $companyId,
            'name' => 'Демо-бригада №1',
            'workshop' => 'Цех вибролитья',
            'foreman_id' => $foreman->id,
            'is_active' => true,
        ]);
        $brigade->members()->sync([$worker1->id, $worker2->id]);

        $bonuses = app(ProductionBonusService::class);
        $today = Carbon::today();

        // Смены этого месяца: подтверждённые дают бонус, последняя — ждёт мастера.
        $shifts = [
            [$today->copy()->subDays(12), 'Плитка «Квадрат» 300×300', [[$worker1->id, 0, 62], [$worker2->id, 0, 48]], true],
            [$today->copy()->subDays(9), 'Бордюр садовый', [[$worker1->id, 180, 0], [$worker2->id, 140, 0]], true],
            [$today->copy()->subDays(5), 'Плитка «Кирпичик» 200×100', [[$worker1->id, 0, 55], [$worker2->id, 0, 51]], true],
            [$today->copy()->subDays(1), 'Урна бетонная', [[$worker1->id, 24, 0], [$worker2->id, 18, 0]], false],
        ];
        // Прошлый месяц — чтобы на странице «Бонусы» было видно накопление.
        $prev = $today->copy()->subMonthNoOverflow();
        $shifts[] = [$prev->copy()->day(12), 'Плитка «Ромб» 190×330', [[$worker1->id, 0, 70], [$worker2->id, 0, 66]], true];
        $shifts[] = [$prev->copy()->day(20), 'Ступени', [[$worker1->id, 0, 34], [$worker2->id, 0, 29]], true];

        foreach ($shifts as [$date, $product, $rows, $confirmed]) {
            $order = WorkOrder::create([
                'company_id' => $companyId,
                'brigade_id' => $brigade->id,
                'date' => $date->toDateString(),
                'product' => $product,
                'status' => $confirmed ? 'confirmed' : 'draft',
                'created_by' => $foreman->id,
                'confirmed_by' => $confirmed ? User::role('admin')->value('id') : null,
                'confirmed_at' => $confirmed ? $date->copy()->addHours(20) : null,
                'note' => 'Демо-смена',
            ]);
            $bonuses->syncLines($order->load('brigade'), array_map(
                fn ($r) => ['user_id' => $r[0], 'qty_pcs' => $r[1], 'qty_m2' => $r[2]],
                $rows,
            ));
        }

        // ── Сделки отдела продаж ──────────────────────────────────────────
        $category = ExpenseCategory::query()->value('id');

        // 1. Своё производство, оплачено полностью → бонус 1%.
        $deal1 = $this->deal([
            'company_id' => $companyId, 'number' => 'DEMO-001',
            'name' => 'Благоустройство двора ЖК «Алатау»',
            'company_name' => 'ТОО «Алатау Строй»', 'client_name' => 'Марат Оспанов',
            'address' => 'г. Алматы, ул. Жандосова 58', 'budget' => 6500000,
            'deal_stage_id' => $stageWon, 'responsible_user_id' => $sales->id,
            'contract_date' => $today->copy()->subDays(16)->toDateString(),
            'deal_type' => 'production',
        ], [
            ['Плитка «Квадрат» 300×300×60', 'м²', 420, 12000],
            ['Бордюр садовый 500×200×80', 'шт', 260, 2500],
        ]);
        $this->money($deal1, 6500000, $today->copy()->subDays(14), 'bank');
        $this->expense($deal1, 1850000, $today->copy()->subDays(13), $category, 'Сырьё и цемент на объект', $companyId);

        // 2. Своё производство, оплачено наполовину → бонус тоже пополам.
        $deal2 = $this->deal([
            'company_id' => $companyId, 'number' => 'DEMO-002',
            'name' => 'Дорожки частного дома, Каскелен',
            'company_name' => 'Частное лицо', 'client_name' => 'Айдос Жумабек',
            'address' => 'г. Каскелен, ул. Абая 14', 'budget' => 3200000,
            'deal_stage_id' => $stageShop, 'responsible_user_id' => $sales->id,
            'contract_date' => $today->copy()->subDays(7)->toDateString(),
            'deal_type' => 'production',
        ], [
            ['Плитка «Кирпичик» 200×100×60', 'м²', 210, 11500],
        ]);
        $this->money($deal2, 1600000, $today->copy()->subDays(6), 'cash');
        $this->expense($deal2, 620000, $today->copy()->subDays(5), $category, 'Сырьё, первая партия', $companyId);

        // 3. Перепродажа: купили → склад → продали. Ставка вдвое выше.
        $deal3 = $this->deal([
            'company_id' => $companyId, 'number' => 'DEMO-003',
            'name' => 'Перепродажа: вазоны и урны, ЖК «Аспан»',
            'company_name' => 'ТОО «Аспан Сервис»', 'client_name' => 'Гульнара Сейтова',
            'address' => 'г. Алматы, пр. Райымбека 220', 'budget' => 1800000,
            'deal_stage_id' => $stageWon, 'responsible_user_id' => $callCenter->id,
            'contract_date' => $today->copy()->subDays(10)->toDateString(),
            'deal_type' => 'resale',
        ], [
            ['Вазон «Чаша» D800', 'шт', 24, 45000],
            ['Урна бетонная «Куб»', 'шт', 30, 24000],
        ]);
        $this->money($deal3, 1800000, $today->copy()->subDays(9), 'bank');
        $this->expense($deal3, 1150000, $today->copy()->subDays(10), $category, 'Закуп товара у поставщика', $companyId);

        $this->command?->info('Демо готово. Пароль всех демо-пользователей: '.self::PASSWORD);
    }

    /** Убрать прошлое демо — чтобы повторный запуск не удваивал данные. */
    public function clear(): void
    {
        $demoUsers = User::where('email', 'like', '%@demo.kz')->pluck('id');

        Brigade::where('name', 'like', 'Демо-%')->get()->each(function (Brigade $b) {
            $b->orders()->each(function (WorkOrder $o) {
                $o->lines()->delete();
                $o->delete();
            });
            $b->members()->detach();
            $b->delete();
        });

        Deal::withTrashed()->where('number', 'like', 'DEMO-%')->get()->each(function (Deal $d) {
            Invoice::where('invoiceable_type', 'deal')->where('invoiceable_id', $d->id)->get()
                ->each(function (Invoice $i) {
                    $i->payments()->delete();
                    $i->forceDelete();
                });
            Expense::withTrashed()->where('expenseable_type', 'deal')->where('expenseable_id', $d->id)->forceDelete();
            DealItem::where('deal_id', $d->id)->delete();
            $d->forceDelete();
        });

        User::whereIn('id', $demoUsers)->get()->each(fn (User $u) => $u->forceDelete());
    }

    private function user(string $email, string $name, string $role, ?int $companyId, float $salary): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'salary' => $salary,
            'is_active' => true,
            'hired_at' => now()->subYear()->toDateString(),
        ]);
        // Роль могли удалить через Настройки → Права доступа: `assignRole`
        // бросает исключение, и демо-данные переставали заводиться вовсе.
        // Заводим недостающую роль пустой — права ей разложит владелец.
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
        if ($companyId) {
            $user->companies()->attach($companyId);
        }

        return $user;
    }

    /** @param array<int, array{0: string, 1: string, 2: float, 3: float}> $items */
    private function deal(array $attributes, array $items): Deal
    {
        $deal = Deal::create($attributes + ['status' => 'active']);

        foreach ($items as $sort => [$name, $unit, $qty, $price]) {
            DealItem::create([
                'deal_id' => $deal->id,
                'product_id' => Product::where('name', $name)->value('id'),
                'name' => $name, 'unit' => $unit,
                'quantity' => $qty, 'price' => $price,
                'amount' => round($qty * $price, 2), 'sort' => $sort,
            ]);
        }

        return $deal;
    }

    /** Счёт и оплата клиента: без денег клиента бонус не начисляется. */
    private function money(Deal $deal, float $amount, Carbon $date, string $method): void
    {
        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'number' => 'INV-'.$deal->number, 'amount' => (float) $deal->budget,
            'status' => 'sent', 'issue_date' => $date->toDateString(),
        ]);
        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => $amount,
            'payment_date' => $date->toDateString(), 'payment_method' => $method,
        ]);
        app(FinanceService::class)->recalcInvoiceStatus($invoice);
    }

    private function expense(Deal $deal, float $amount, Carbon $date, ?int $categoryId, string $note, ?int $companyId): void
    {
        Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'category_id' => $categoryId, 'amount' => $amount,
            'date' => $date->toDateString(), 'description' => $note,
            'status' => 'confirmed', 'payment_method' => 'bank',
            'company_id' => $companyId, 'confirmed_at' => $date->toDateString(),
        ]);
    }
}
