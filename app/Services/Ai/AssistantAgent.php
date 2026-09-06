<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Support\AiKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Агент помощника: цикл «модель просит инструмент → сервер выполняет →
 * модель отвечает». Голого чата здесь нет — все цифры приходят из
 * AssistantTools, то есть из самой системы.
 *
 * Провайдер выбирается по виду ключа: «sk-ant…» — Anthropic, иначе Google
 * Gemini (бесплатный тариф). Оба вызываются обычным HTTP, без SDK: один
 * слой на два провайдера проще, чем SDK для одного и HTTP для другого.
 */
class AssistantAgent
{
    /** Больше шести кругов — признак зацикливания, а не работы. */
    private const MAX_ROUNDS = 6;

    private const TIMEOUT = 55;

    private const ANTHROPIC_MODEL = 'claude-sonnet-5';

    /**
     * Модели Gemini по убыванию предпочтения: у каждой свои квоты, поэтому
     * при 429/404 переходим к следующей.
     */
    private const GEMINI_MODELS = [
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemini-3.6-flash',
        'gemini-2.5-flash',
    ];

    /** Рабочая модель Gemini на время жизни процесса — чтобы не перебирать каждый раз. */
    private static ?string $geminiModel = null;

    /** Инструменты, которые модель вызвала в текущем ответе. */
    private array $usedTools = [];

    /**
     * Ответ на вопрос.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{answer: string, used_tools: array<int, string>}
     *
     * @throws AssistantException человеческим языком — контроллер отдаёт текст как есть
     */
    public function ask(User $user, string $question, array $history = []): array
    {
        $key = AiKey::get();

        if (! $key) {
            throw new AssistantException('Ключ ИИ не настроен. Откройте Настройки → ИИ-помощник и вставьте бесплатный ключ Google Gemini (aistudio.google.com/apikey).');
        }

        $this->usedTools = [];
        $tools = new AssistantTools($user);

        $answer = str_starts_with($key, 'sk-ant')
            ? $this->runAnthropic($key, $user, $question, $history, $tools)
            : $this->runGemini($key, $user, $question, $history, $tools);

        return ['answer' => $answer, 'used_tools' => array_values(array_unique($this->usedTools))];
    }

    // ------------------------------------------------------------------
    // Системный промпт
    // ------------------------------------------------------------------

    private function systemPrompt(User $user): string
    {
        $roles = $user->getRoleNames()->implode(', ');
        $today = now()->translatedFormat('d MMMM YYYY');

        return <<<TXT
        Ты — помощник внутри ERP компании QAZAQ TAS (завод изделий из мраморного композита:
        тротуарная плитка, бордюры, вазоны, скамьи, урны, ступени; площадки в Шымкенте,
        Алматы и Таразе). Валюта — тенге (₸). Сегодня {$today}.
        С тобой говорит {$user->name}, роль: {$roles}.
        Отвечай по-русски, коротко и по делу, суммы пиши с разделителями тысяч (1 250 000 ₸).

        ЖЁСТКОЕ ПРАВИЛО: цифры и факты бери ТОЛЬКО из инструментов. Никогда не придумывай
        числа, не оценивай «примерно» и не считай по памяти. Нет данных — так и скажи.

        Списки не пересчитывай сам: у инструментов уже есть готовые поля count и sum —
        используй их. Если перечисляешь позиции, перечисли ВСЕ, которые вернул инструмент,
        без «и ещё несколько».

        Различай похожие метрики и называй обе, если вопрос неоднозначен: активные сделки
        это не то же самое, что закрытые за месяц; сумма договоров — не то же, что прибыль.

        В системе три разных «прибыли», не путай их. «Чистая прибыль» на странице Финансы → Обзор
        (инструмент finance_overview: все поступления денег минус все расходы) — именно её называй на
        вопрос «чистая прибыль» или «сколько заработали». Прибыль фирмы по сделкам из «Сводного
        отчёта» — sales_report.company_profit. По выигранным сделкам — «Аналитика». Всегда говори,
        какую цифру приводишь, и при вопросе про чистую прибыль одной строкой упомяни отчётную.

        Период НЕ выдумывай: если пользователь не назвал его, вызывай инструменты без from/to —
        они считают за всё время, как страницы системы. Спросили «за месяц» — передай даты
        и назови и цифру за период, и общую, если инструмент вернул обе.

        Идентификаторы всегда оформляй маркдаун-ссылкой вида [НОМЕР](link), беря link из
        поля link соответствующей сущности. Никогда не пиши номер голым текстом.

        Если инструмент вернул поле error — объясни пользователю причину вежливо и
        предложи, что делать дальше. Не пытайся обойти ограничение.
        TXT;
    }

    // ------------------------------------------------------------------
    // Google Gemini
    // ------------------------------------------------------------------

    /** @param array<int, array{role: string, content: string}> $history */
    private function runGemini(string $key, User $user, string $question, array $history, AssistantTools $tools): string
    {
        $contents = [];

        foreach ($history as $m) {
            $contents[] = [
                'role' => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

        $declarations = array_map(fn ($t) => [
            'name' => $t['name'],
            'description' => $t['description'],
            'parameters' => $t['parameters'],
        ], $tools->schema());

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $data = $this->geminiCall($key, [
                'system_instruction' => ['parts' => [['text' => $this->systemPrompt($user)]]],
                'contents' => $contents,
                'tools' => [['function_declarations' => $declarations]],
                'generationConfig' => [
                    'maxOutputTokens' => 1500,
                    // Без низкого уровня «размышлений» ответ приходит в разы дольше.
                    'thinkingConfig' => ['thinkingLevel' => 'low'],
                ],
            ]);

            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $calls = array_values(array_filter($parts, fn ($p) => isset($p['functionCall'])));

            if (! $calls) {
                $text = implode("\n", array_map(fn ($p) => $p['text'] ?? '', $parts));

                if (trim($text) !== '') {
                    return trim($text);
                }

                throw new AssistantException('Модель вернула пустой ответ. Попробуйте переформулировать вопрос.');
            }

            // Ход модели возвращаем в диалог — иначе она не увидит, что именно
            // спрашивала. Пересобираем вручную: пустой объект args приходит от
            // PHP обратно пустым СПИСКОМ, и Gemini отвечает «Proto field is not
            // repeating, cannot start list».
            $contents[] = ['role' => 'model', 'parts' => $this->modelParts($parts)];

            $responses = [];
            foreach ($calls as $part) {
                $name = $part['functionCall']['name'] ?? '';
                $args = (array) ($part['functionCall']['args'] ?? []);
                $this->usedTools[] = $name;

                $responses[] = ['functionResponse' => [
                    'name' => $name,
                    // response ОБЯЗАН быть объектом: списки заворачиваем.
                    'response' => $this->asObject($tools->run($name, $args)),
                ]];
            }

            $contents[] = ['role' => 'user', 'parts' => $responses];
        }

        throw new AssistantException('Не удалось собрать ответ за отведённое число шагов. Задайте вопрос конкретнее.');
    }

    /**
     * Запрос к Gemini с перебором моделей: у каждой свои квоты, и 429/404
     * означают «попробуй следующую», а не «всё сломалось».
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function geminiCall(string $key, array $body): array
    {
        $models = self::$geminiModel
            ? array_merge([self::$geminiModel], array_diff(self::GEMINI_MODELS, [self::$geminiModel]))
            : self::GEMINI_MODELS;

        $lastError = null;

        foreach ($models as $model) {
            foreach ([true, false] as $withThinking) {
                $payload = $body;

                if (! $withThinking) {
                    unset($payload['generationConfig']['thinkingConfig']);
                }

                try {
                    $response = Http::timeout(self::TIMEOUT)
                        ->withHeaders(['x-goog-api-key' => $key])
                        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", $payload);
                } catch (ConnectionException $e) {
                    throw new AssistantException('Не удалось связаться с сервисом ИИ — проверьте интернет на сервере.');
                }

                if ($response->successful()) {
                    self::$geminiModel = $model;

                    return $response->json();
                }

                $status = $response->status();
                $lastError = $response->json('error.message') ?: $response->body();

                if ($status === 400 && $withThinking && str_contains(mb_strtolower((string) $lastError), 'thinking')) {
                    continue; // модель не знает thinkingConfig — повторим без него
                }
                if (in_array($status, [401, 403], true)) {
                    throw new AssistantException('Ключ ИИ не принят сервисом. Проверьте его в Настройках.');
                }
                if (in_array($status, [429, 404], true)) {
                    break; // квота или нет такой модели — следующая модель
                }

                throw new AssistantException("Сервис ИИ ответил ошибкой: {$lastError}");
            }
        }

        throw new AssistantException('Лимит бесплатных запросов исчерпан — подождите минуту и повторите. ('.mb_strimwidth((string) $lastError, 0, 120, '…').')');
    }

    // ------------------------------------------------------------------
    // Anthropic
    // ------------------------------------------------------------------

    /** @param array<int, array{role: string, content: string}> $history */
    private function runAnthropic(string $key, User $user, string $question, array $history, AssistantTools $tools): string
    {
        $messages = [];

        foreach ($history as $m) {
            $messages[] = [
                'role' => ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
                'content' => (string) ($m['content'] ?? ''),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $schema = array_map(fn ($t) => [
            'name' => $t['name'],
            'description' => $t['description'],
            'input_schema' => $t['parameters'],
        ], $tools->schema());

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            try {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => self::ANTHROPIC_MODEL,
                        'max_tokens' => 1500,
                        'system' => $this->systemPrompt($user),
                        'messages' => $messages,
                        'tools' => $schema,
                    ]);
            } catch (ConnectionException $e) {
                throw new AssistantException('Не удалось связаться с сервисом ИИ — проверьте интернет на сервере.');
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new AssistantException('Ключ ИИ не принят сервисом. Проверьте его в Настройках.');
            }
            if ($response->status() === 429) {
                throw new AssistantException('Лимит запросов исчерпан — подождите минуту и повторите.');
            }
            if (! $response->successful()) {
                throw new AssistantException('Сервис ИИ ответил ошибкой: '.($response->json('error.message') ?: $response->body()));
            }

            $data = $response->json();
            $content = $data['content'] ?? [];

            if (($data['stop_reason'] ?? '') !== 'tool_use') {
                $text = implode("\n", array_map(
                    fn ($b) => ($b['type'] ?? '') === 'text' ? $b['text'] : '',
                    $content,
                ));

                if (trim($text) !== '') {
                    return trim($text);
                }

                throw new AssistantException('Модель вернула пустой ответ. Попробуйте переформулировать вопрос.');
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];

            $results = [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $this->usedTools[] = $block['name'];
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => json_encode($tools->run($block['name'], (array) ($block['input'] ?? [])), JSON_UNESCAPED_UNICODE),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new AssistantException('Не удалось собрать ответ за отведённое число шагов. Задайте вопрос конкретнее.');
    }

    /**
     * Ход модели в том виде, в каком его примет Gemini обратно.
     *
     * Части возвращаем КАК ЕСТЬ — вместе с thoughtSignature: Gemini 3 требует
     * подпись рядом с functionCall и без неё отвечает 400 «missing
     * thought_signature». Единственная правка — args: json_encode превращает
     * пустой массив PHP в «[]», а протокол ждёт объект, отсюда была ошибка
     * «Proto field is not repeating».
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, array<string, mixed>>
     */
    private function modelParts(array $parts): array
    {
        foreach ($parts as &$part) {
            if (isset($part['functionCall'])) {
                $part['functionCall']['args'] = (object) ((array) ($part['functionCall']['args'] ?? []));
            }
        }

        return array_values($parts);
    }

    /**
     * Gemini требует, чтобы functionResponse.response был объектом.
     * Списки на верхнем уровне заворачиваем в {result: ...}, пустой ответ —
     * тоже объект, иначе он уедет пустым списком и получит ту же ошибку.
     *
     * @param  array<mixed>  $value
     */
    private function asObject(array $value): object
    {
        return (object) (array_is_list($value) ? ['result' => $value] : $value);
    }
}
