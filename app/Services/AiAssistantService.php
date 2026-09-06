<?php

namespace App\Services;

use App\Models\AiConversation;
use App\Support\AiKey;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ИИ-помощник руководителя: вопрос → ответ со знанием цифр системы.
 *
 * Помощник ничего не меняет в ERP — только читает сводку (сделки по этапам,
 * цех, склад, задачи, выручка) и отвечает текстом. Сводка собирается из БД
 * агрегатами и кладётся в system prompt; персональных данных клиентов
 * (телефоны, БИН, адреса) в промпте нет — руководителю для ответа хватает
 * цифр, а утечь наружу нечему.
 *
 * Ответ пишет модель по ключу из Настроек. Без ключа
 * помощник не умолкает: типовые вопросы («склад», «просрочка», «деньги»)
 * обслуживает LocalAnswerService прямо из базы — бесплатно и мгновенно.
 * Ключ нужен только для свободных формулировок и деловых текстов.
 */
class AiAssistantService
{
    /** Сколько последних реплик диалога отправляем модели. */
    private const HISTORY_LIMIT = 20;

    public function __construct(private LocalAnswerService $local) {}

    /**
     * Ответ помощника на вопрос. Ничего не пишет в БД — сохраняет контроллер.
     *
     * @return array{content: string, input_tokens: ?int, output_tokens: ?int, ok: bool}
     */
    public function answer(AiConversation $conversation, string $question): array
    {
        // Без ключа помощник не умолкает: типовые вопросы («склад»,
        // «просрочка», «деньги») он берёт прямо из базы — бесплатно и сразу.
        if (! AiKey::isSet() || ! class_exists(\Anthropic\Client::class)) {
            return $this->offline($question);
        }

        try {
            return $this->viaClaude($conversation, $question);
        } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
            report($e);

            return $this->fallback($question, match ($e->type?->value) {
                'rate_limit_error', 'overloaded_error' => __('Помощник сейчас перегружен. Попробуйте задать вопрос через минуту.'),
                'authentication_error', 'permission_error' => __('Ключ ИИ-помощника отклонён. Проверьте его в Настройках.'),
                default => __('Помощник не смог ответить из-за ошибки связи. Попробуйте ещё раз.'),
            });
        } catch (\Throwable $e) {
            report($e);

            return $this->fallback($question, __('Помощник не смог ответить из-за ошибки связи. Попробуйте ещё раз.'));
        }
    }

    /** @return array{content: string, input_tokens: ?int, output_tokens: ?int, ok: bool} */
    private function viaClaude(AiConversation $conversation, string $question): array
    {
        $client = new \Anthropic\Client(apiKey: (string) AiKey::get());

        $messages = $conversation->messages()
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();

        $messages[] = ['role' => 'user', 'content' => $question];

        $message = $client->messages->create(
            model: (string) config('services.anthropic.assistant_model'),
            maxTokens: 8000,
            // Thinking на Opus 5 включён по умолчанию; effort «medium» держит
            // ответ в пределах обычного веб-таймаута и заметно дешевле —
            // вопрос-ответ по готовой сводке глубокого перебора не требует.
            outputConfig: ['effort' => 'medium'],
            system: [
                ['type' => 'text', 'text' => $this->instructions()],
                ['type' => 'text', 'text' => "Актуальная сводка по компании на ".now()->format('d.m.Y H:i').":\n\n".$this->context()],
            ],
            messages: $messages,
        );

        // При adaptive thinking первым блоком может идти thinking — берём текст.
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= ($text === '' ? '' : "\n\n").$block->text;
            }
        }

        if (trim($text) === '') {
            // Модель отказалась отвечать (stopReason refusal) либо вернула пусто.
            return $this->failure(__('Помощник не смог ответить на этот вопрос. Попробуйте переформулировать.'));
        }

        return [
            'content' => trim($text),
            'input_tokens' => $message->usage->inputTokens ?? null,
            'output_tokens' => $message->usage->outputTokens ?? null,
            'ok' => true,
        ];
    }

    /**
     * Режим без ИИ: ответ прямо из базы. Тему не узнали — показываем список
     * того, что помощник умеет без ключа.
     *
     * @return array{content: string, input_tokens: null, output_tokens: null, ok: bool}
     */
    private function offline(string $question): array
    {
        $answer = $this->local->answer($question);

        return $answer !== null
            ? ['content' => $answer, 'input_tokens' => null, 'output_tokens' => null, 'ok' => true]
            : $this->failure($this->local->help());
    }

    /**
     * ИИ не ответил (перегрузка, сеть, ключ). Если вопрос типовой — выдаём
     * данные из базы, приписав, почему ответ короче обычного.
     *
     * @return array{content: string, input_tokens: null, output_tokens: null, ok: bool}
     */
    private function fallback(string $question, string $reason): array
    {
        $answer = $this->local->answer($question);

        if ($answer === null) {
            return $this->failure($reason);
        }

        return [
            'content' => $answer."\n\n_".$reason.' Ответ собран напрямую из системы._',
            'input_tokens' => null,
            'output_tokens' => null,
            'ok' => true,
        ];
    }

    /** @return array{content: string, input_tokens: null, output_tokens: null, ok: false} */
    private function failure(string $text): array
    {
        return ['content' => $text, 'input_tokens' => null, 'output_tokens' => null, 'ok' => false];
    }

    /** Кто такой помощник и как он должен отвечать. */
    private function instructions(): string
    {
        return <<<'TXT'
        Ты — помощник руководителя компании QAZAQ TAS: завод изделий из мраморного композита
        (тротуарная плитка, бордюры, вазоны, скамьи, урны, ступени) с площадками в Шымкенте,
        Алматы и Таразе. Ты работаешь внутри ERP компании и отвечаешь директору или
        администратору на вопросы о делах компании, а также помогаешь с деловыми текстами.

        Правила:
        - Отвечай на языке вопроса: спросили по-русски — отвечай по-русски, по-казахски — по-казахски.
        - Опирайся на сводку данных ниже. Если в ней нет нужных цифр, прямо скажи, каких данных
          не хватает и где в системе их посмотреть, — не выдумывай числа.
        - Отвечай кратко и по делу: сначала ответ, потом при необходимости пояснение.
          Списки и таблицы используй, когда они правда помогают.
        - Суммы указывай в тенге, разряды разделяй пробелом.
        - Ты только читаешь данные и советуешь: изменить что-то в системе (создать сделку,
          передвинуть этап) ты не можешь — подскажи руководителю, где это сделать.
        TXT;
    }

    /**
     * Сводка по компании из БД. Кэш на 5 минут: за это время цифры не
     * устаревают, а серия вопросов подряд не бьёт по базе повторно.
     */
    private function context(): string
    {
        $companyId = CurrentCompany::id();

        return Cache::remember(
            'ai.assistant.context.'.($companyId ?: 'all'),
            now()->addMinutes(5),
            fn () => $this->buildContext($companyId),
        );
    }

    private function buildContext(?int $companyId): string
    {
        try {
            $blocks = array_filter([
                $this->dealsBlock($companyId),
                $this->workshopBlock($companyId),
                $this->stockBlock($companyId),
                $this->tasksBlock(),
                $this->moneyBlock(),
            ]);

            return implode("\n\n", $blocks) ?: 'Данных в системе пока нет.';
        } catch (\Throwable $e) {
            // Сводка — обогащение ответа, а не сам ответ: помощник должен
            // ответить и без цифр, но ошибку мы видим в логах.
            report($e);

            return 'Сводка по системе временно недоступна — отвечай по общим знаниям и предупреди об этом.';
        }
    }

    private function dealsBlock(?int $companyId): string
    {
        $rows = DB::table('deals')
            ->join('deal_stages', 'deal_stages.id', '=', 'deals.deal_stage_id')
            ->whereNull('deals.deleted_at')
            ->when($companyId, fn ($q) => $q->where('deals.company_id', $companyId))
            ->groupBy('deal_stages.id', 'deal_stages.name', 'deal_stages.order')
            ->orderBy('deal_stages.order')
            ->selectRaw('deal_stages.name as stage, COUNT(*) as cnt, COALESCE(SUM(deals.budget), 0) as total')
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $overdue = DB::table('deals')
            ->join('deal_stages', 'deal_stages.id', '=', 'deals.deal_stage_id')
            ->whereNull('deals.deleted_at')
            ->where('deal_stages.is_won', false)
            ->whereNotNull('deals.deadline')
            ->whereDate('deals.deadline', '<', now()->toDateString())
            ->when($companyId, fn ($q) => $q->where('deals.company_id', $companyId))
            ->count();

        $lines = $rows->map(fn ($r) => "- {$r->stage}: {$r->cnt} шт, ".$this->money($r->total));

        return "СДЕЛКИ ПО ЭТАПАМ:\n".$lines->implode("\n")."\n- Просрочено (дедлайн прошёл, сделка не закрыта): {$overdue} шт";
    }

    private function workshopBlock(?int $companyId): string
    {
        $rows = DB::table('projects')
            ->join('project_stages', 'project_stages.id', '=', 'projects.project_stage_id')
            ->whereNull('projects.deleted_at')
            ->when($companyId, fn ($q) => $q->where('project_stages.company_id', $companyId))
            ->groupBy('project_stages.id', 'project_stages.name', 'project_stages.order')
            ->orderBy('project_stages.order')
            ->selectRaw('project_stages.name as stage, COUNT(*) as cnt')
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $stuck = DB::table('projects')
            ->join('project_stages', 'project_stages.id', '=', 'projects.project_stage_id')
            ->whereNull('projects.deleted_at')
            ->where('project_stages.is_completed', false)
            ->where('projects.created_at', '<', now()->subDays(3))
            ->when($companyId, fn ($q) => $q->where('project_stages.company_id', $companyId))
            ->count();

        $lines = $rows->map(fn ($r) => "- {$r->stage}: {$r->cnt} заказ(ов)");

        return "ЦЕХ (заказы в производстве):\n".$lines->implode("\n")."\n- В работе дольше 3 дней: {$stuck}";
    }

    private function stockBlock(?int $companyId): string
    {
        $rows = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->whereNull('products.deleted_at')
            ->when($companyId, fn ($q) => $q->where('stock_movements.company_id', $companyId))
            ->groupBy('products.id', 'products.name', 'products.unit', 'products.min_stock')
            ->havingRaw('SUM(stock_movements.qty) <> 0')
            ->orderByRaw('SUM(stock_movements.qty) DESC')
            ->limit(15)
            ->selectRaw('products.name, products.unit, products.min_stock, SUM(stock_movements.qty) as qty')
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = $rows->map(function ($r) {
            $low = $r->min_stock !== null && (float) $r->qty < (float) $r->min_stock ? ' — НИЖЕ МИНИМУМА' : '';

            return "- {$r->name}: ".rtrim(rtrim(number_format((float) $r->qty, 2, ',', ' '), '0'), ',')." {$r->unit}{$low}";
        });

        return "СКЛАД (остатки, топ-15):\n".$lines->implode("\n");
    }

    private function tasksBlock(): string
    {
        $open = DB::table('tasks')->whereNull('deleted_at')->where('status', '!=', 'done')->count();

        if ($open === 0) {
            return '';
        }

        $overdue = DB::table('tasks')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        $byUser = DB::table('tasks')
            ->join('users', 'users.id', '=', 'tasks.assignee_id')
            ->whereNull('tasks.deleted_at')
            ->where('tasks.status', '!=', 'done')
            ->groupBy('users.id', 'users.name')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->selectRaw('users.name, COUNT(*) as cnt')
            ->get()
            ->map(fn ($r) => "  · {$r->name}: {$r->cnt}")
            ->implode("\n");

        return "ЗАДАЧИ: открыто {$open}, из них просрочено {$overdue}.\nПо ответственным:\n".$byUser;
    }

    private function moneyBlock(): string
    {
        // Границы суток, а не строки-даты: иначе платежи последнего дня
        // не попадали в сумму (payment_date хранит дату со временем).
        $sum = fn ($from, $to) => (float) DB::table('payments')
            ->whereBetween('payment_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('amount');

        $now = now();
        $this_ = $sum($now->copy()->startOfMonth(), $now);
        $prev = $sum($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth());

        if ($this_ <= 0 && $prev <= 0) {
            return '';
        }

        return 'ПОСТУПЛЕНИЯ ДЕНЕГ: за текущий месяц '.$this->money($this_).', за прошлый месяц полностью '.$this->money($prev).'.';
    }

    private function money(float|int|string $v): string
    {
        return number_format((float) $v, 0, ',', ' ').' ₸';
    }
}
