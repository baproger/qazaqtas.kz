<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    private function assertOwnership(User $user, ?Model $entity): void
    {
        // Изоляция фирм: расходы чужой компании (BAIA/ASU) недоступны никому,
        // кто к этой компании не привязан, — включая финансиста и директора.
        $companyId = $entity instanceof Project ? $entity->deal?->company_id : $entity?->company_id;
        abort_unless($entity === null || $user->worksInCompany($companyId ? (int) $companyId : null), 403);

        if ($user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            abort_unless($entity && $entity->responsible_user_id === $user->id, 403);
        }
    }

    private function resolve(?string $type, ?int $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return $type === 'project' ? Project::find($id) : Deal::find($id);
    }

    /**
     * Контроль маржи: подтверждённые расходы сделки превысили 60% от суммы
     * договора → уведомить финансистов (порог пересекается один раз).
     */
    private function checkExpenseThreshold(?Model $entity, float $addedAmount): void
    {
        $deal = $entity instanceof Project ? $entity->deal : $entity;
        if (! $deal instanceof Deal || (float) $deal->budget <= 0) {
            return;
        }

        $spent = (float) Expense::where('status', 'confirmed')
            ->where(fn ($q) => $q
                ->where(fn ($d) => $d->where('expenseable_type', 'deal')->where('expenseable_id', $deal->id))
                ->orWhere(fn ($p) => $p->where('expenseable_type', 'project')
                    ->whereIn('expenseable_id', Project::where('deal_id', $deal->id)->select('id'))))
            ->sum('amount');

        $limit = (float) $deal->budget * 0.6;
        if ($spent > $limit && ($spent - $addedAmount) <= $limit) {
            User::where('is_active', true)->role('financist')->get()
                ->each(fn (User $u) => $u->notify(new \App\Notifications\ExpenseThresholdExceeded($deal, $spent)));
        }
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Expense::class);
        $entity = $this->resolve($request->input('expenseable_type', 'deal'), (int) $request->input('expenseable_id'));
        $this->assertOwnership($request->user(), $entity);

        $data = $request->validated();
        unset($data['file']);
        // Чек хранится вне public-корня (storage/app/private), как и документы.
        $data['file_path'] = $request->hasFile('file') ? $request->file('file')->store('receipts', 'local') : null;
        // Автор проставляется автоматически — заполнить расход за другого нельзя.
        $data['responsible_user_id'] = $request->user()->id;
        $data['type'] ??= 'direct';

        // Расход КОМПАНИИ (без сделки): аренда/комуслуги/интернет/бензин и т.п.
        // Вводит только бухгалтер/админ, категория обязательна, склад — нельзя.
        if ($entity === null) {
            abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Расход компании вводит бухгалтер или админ.');
            if (empty($data['category_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'category_id' => 'Выберите категорию расхода (аренда, интернет, бензин…).',
                ]);
            }
            if (! empty($data['material_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'material_id' => 'Списание со склада делается из карточки сделки/заказа.',
                ]);
            }
            $data['company_id'] = \App\Support\CurrentCompany::id() ?: null;
        }

        // Расход по материалам: списываем остаток со склада компании сделки.
        if (! empty($data['material_id'])) {
            $material = \App\Models\Material::findOrFail($data['material_id']);

            $entityCompanyId = $entity instanceof Project ? $entity->deal?->company_id : $entity?->company_id;
            if ($material->company_id && (int) $material->company_id !== (int) $entityCompanyId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'material_id' => 'Материал со склада другой компании.',
                ]);
            }
            // Первичная проверка (быстрый отказ + подсказка остатка); финальная,
            // защищённая от гонки, — под блокировкой строки внутри транзакции.
            if ((float) $material->quantity < (float) $data['qty']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'qty' => 'Недостаточно на складе: остаток '.rtrim(rtrim(number_format((float) $material->quantity, 2, '.', ' '), '0'), '.').' '.$material->unit.'.',
                ]);
            }

            // Сумма считается автоматически: количество × закупочная цена
            // (последняя цена прихода на материале). Если цена не заведена
            // (старые позиции) — сумма обязательна вручную: без неё возник бы
            // «подтверждённый» расход на 0 ₸ со списанием склада.
            if ((float) $material->price > 0) {
                $data['amount'] = round((float) $data['qty'] * (float) $material->price, 2);
            } elseif ((float) ($data['amount'] ?? 0) <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'У материала не заведена закупочная цена — укажите сумму расхода вручную.',
                ]);
            }
            $data['amount'] = (float) $data['amount'];

            // Внутреннее списание — подтверждение бухгалтера не требуется.
            $data['status'] = 'confirmed';
            $data['description'] = trim(($data['description'] ?? '')) !== ''
                ? $data['description']
                : 'Материал: '.$material->name.' × '.rtrim(rtrim(number_format((float) $data['qty'], 2, '.', ''), '0'), '.').' '.$material->unit;

            \Illuminate\Support\Facades\DB::transaction(function () use ($data, $material) {
                // Блокируем строку материала и перечитываем остаток ВНУТРИ
                // транзакции: два параллельных списания не уведут склад в минус.
                $locked = \App\Models\Material::whereKey($material->id)->lockForUpdate()->first();
                if ((float) $locked->quantity < (float) $data['qty']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'qty' => 'Недостаточно на складе: остаток '.rtrim(rtrim(number_format((float) $locked->quantity, 2, '.', ' '), '0'), '.').' '.$locked->unit.'.',
                    ]);
                }
                Expense::create($data);
                $locked->decrement('quantity', $data['qty']);
            });

            $this->checkExpenseThreshold($entity, (float) $data['amount']);

            return back()->with('success', 'Расход по материалам добавлен — остаток на складе списан.');
        }

        // Прочий расход: бухгалтер/админ подтверждают сразу; расход менеджера
        // (и директора) ждёт подтверждения бухгалтера — чек + нал/банк.
        $isAccountant = $request->user()->hasAnyRole(['admin', 'financist']);
        $data['status'] = $isAccountant ? 'confirmed' : 'pending';
        if ($isAccountant) {
            $data['confirmed_by'] = $request->user()->id;
            $data['confirmed_at'] = now();
        }
        // Способ оплаты (нал/банк) автор выбирает при создании; у pending-расхода
        // бухгалтер может поменять его при подтверждении.

        $expense = Expense::create($data);

        if ($isAccountant) {
            $this->checkExpenseThreshold($entity, (float) $expense->amount);
        }

        if (! $isAccountant) {
            $this->notifyAccountants($expense, $entity);

            return back()->with('success', 'Расход отправлен бухгалтеру на подтверждение.');
        }

        return back()->with('success', 'Расход добавлен.');
    }

    /**
     * Бухгалтеру: уведомление + задача «Подтвердить расход…» на сделке/заказе.
     */
    private function notifyAccountants(Expense $expense, ?Model $entity): void
    {
        $title = 'Подтвердить расход #'.$expense->id.' — '.number_format((float) $expense->amount, 0, '.', ' ').' ₸'
            .($entity?->number ? ' ('.$entity->number.')' : '');

        $financists = User::where('is_active', true)->role('financist')->get();
        foreach ($financists as $fin) {
            if ($entity && method_exists($entity, 'tasks')) {
                $entity->tasks()->create([
                    'title' => $title,
                    'status' => 'new',
                    'priority' => 'high',
                    'assignee_id' => $fin->id,
                    'creator_id' => $expense->responsible_user_id ?? $fin->id,
                    'start_date' => now(),
                    'due_date' => now()->addDays(3),
                ]);
            }
            $fin->notify(new \App\Notifications\ExpensePending($expense));
        }
    }

    /**
     * Подтверждение расхода бухгалтером: обязательный чек (уже приложенный или
     * загружаемый сейчас) + способ оплаты нал/банк (касса).
     */
    public function confirm(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Расход подтверждает бухгалтер или админ.');
        $this->assertOwnership($request->user(), $expense->expenseable);

        if ($expense->status === 'confirmed') {
            return back()->with('error', 'Расход уже подтверждён.');
        }

        $data = $request->validate([
            'payment_method' => ['required', \Illuminate\Validation\Rule::in(['cash', 'bank'])],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
        ], ['payment_method.required' => 'Выберите способ оплаты: наличные или банк.']);

        if ($request->hasFile('file')) {
            if ($expense->file_path) {
                Storage::disk('local')->delete($expense->file_path);
            }
            $expense->file_path = $request->file('file')->store('receipts', 'local');
        }
        if (! $expense->file_path) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'Без чека расход не подтверждается — прикрепите фото или PDF.',
            ]);
        }

        $expense->update([
            'file_path' => $expense->file_path,
            'status' => 'confirmed',
            'payment_method' => $data['payment_method'],
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        // Закрываем задачи «Подтвердить расход #N …» у бухгалтеров.
        \App\Models\Task::where('title', 'like', 'Подтвердить расход #'.$expense->id.' %')
            ->where('status', '!=', 'done')
            ->get()->each(fn ($t) => $t->update(['status' => 'done', 'completed_at' => now()]));

        // Автору — уведомление о подтверждении.
        $expense->responsible?->notify(new \App\Notifications\ExpenseConfirmed($expense));
        $this->checkExpenseThreshold($expense->expenseable, (float) $expense->amount);

        // Остальным бухгалтерам — «расход уже подтверждён (Имя)», чтобы не
        // подтверждали повторно. Кроме того, кто подтвердил, и автора.
        User::where('is_active', true)->role('financist')
            ->where('id', '!=', $request->user()->id)
            ->where('id', '!=', $expense->responsible_user_id)
            ->get()
            ->each(fn ($fin) => $fin->notify(new \App\Notifications\ExpenseHandled($expense, $request->user())));

        return back()->with('success', 'Расход подтверждён ('.($data['payment_method'] === 'cash' ? 'наличные' : 'банк').').');
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense);
        $this->assertOwnership($request->user(), $expense->expenseable);

        $data = $request->validated();
        unset($data['file']);
        // Материал/количество после создания не меняются (иначе разъедется склад) —
        // удалите расход (остаток вернётся) и создайте заново.
        unset($data['material_id'], $data['qty']);
        // Статус, способ оплаты, подтверждающий и полиморфная привязка — НЕ через
        // update(): статус/оплату ставит только confirm() (бухгалтер, с чеком),
        // родитель расхода неизменен. Иначе менеджер сам себе подтвердил бы расход
        // (обход бухгалтера) или увёл его на чужую сделку/компанию.
        unset($data['status'], $data['payment_method'], $data['confirmed_by'], $data['confirmed_at'],
            $data['expenseable_type'], $data['expenseable_id']);
        // Сумма материального расхода — производная (кол-во × цена), руками не
        // правится: попытка её изменить получает честную ошибку, а не молчаливый
        // игнор с флешем «Расход обновлён».
        if ($expense->material_id && (float) ($expense->material?->price ?? 0) > 0) {
            if (isset($data['amount']) && abs((float) $data['amount'] - (float) $expense->amount) > 0.005) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'Сумма расхода по материалам считается автоматически (количество × цена) и не редактируется — удалите расход (остаток вернётся) и создайте заново.',
                ]);
            }
            unset($data['amount']);
        } elseif (! isset($data['amount'])) {
            // amount необязателен при material_id: null в NOT NULL колонку → 500.
            unset($data['amount']);
        }
        if ($request->hasFile('file')) {
            if ($expense->file_path) {
                Storage::disk('local')->delete($expense->file_path);
            }
            $data['file_path'] = $request->file('file')->store('receipts', 'local');
        }
        // responsible_user_id намеренно не трогаем — автор расхода неизменен.
        $expense->update($data);

        return back()->with('success', 'Расход обновлён.');
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        $this->authorize('view', $expense);
        $this->assertOwnership(request()->user(), $expense->expenseable);

        abort_unless($expense->file_path && Storage::disk('local')->exists($expense->file_path), 404);

        $name = 'чек-' . $expense->id . '.' . pathinfo($expense->file_path, PATHINFO_EXTENSION);

        // Отдаём inline — чек открывается в браузере на просмотр, без скачивания.
        // nosniff: файл загружен пользователем, запрещаем браузеру угадывать тип (защита от XSS).
        return Storage::disk('local')->response($expense->file_path, $name, [
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);
        $this->assertOwnership(request()->user(), $expense->expenseable);

        // Удаление расхода по материалам возвращает количество на склад.
        \Illuminate\Support\Facades\DB::transaction(function () use ($expense) {
            if ($expense->material_id && $expense->qty && $expense->material) {
                $expense->material->increment('quantity', $expense->qty);
            }
            $expense->delete();
        });

        \App\Support\FinanceAudit::notifyDeleted('Расход на '.number_format((float) $expense->amount, 0, '.', ' ').' ₸'.($expense->description ? ' («'.\Illuminate\Support\Str::limit($expense->description, 60).'»)' : ''));

        return back()->with('success', $expense->material_id ? 'Расход удалён — остаток возвращён на склад.' : 'Расход удалён.');
    }
}
