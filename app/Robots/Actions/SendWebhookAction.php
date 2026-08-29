<?php

namespace App\Robots\Actions;

use App\Models\Deal;
use App\Models\StageRobotRun;
use App\Robots\Placeholders;
use App\Robots\RobotActionInterface;
use Illuminate\Support\Facades\Http;

class SendWebhookAction implements RobotActionInterface
{
    public static function type(): string
    {
        return 'send_webhook';
    }

    public static function label(): string
    {
        return 'Отправить вебхук';
    }

    public static function schema(): array
    {
        return [
            ['key' => 'url', 'label' => 'URL', 'type' => 'text', 'required' => true],
            ['key' => 'secret', 'label' => 'Секрет (подпись X-Signature, HMAC-SHA256)', 'type' => 'text'],
            ['key' => 'extra', 'label' => 'Доп. поля JSON', 'type' => 'textarea', 'hint' => '{"source": "erp"}'],
        ];
    }

    public function handle(Deal $deal, array $payload, StageRobotRun $run): array
    {
        $url = (string) ($payload['url'] ?? '');
        if (! preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Некорректный URL вебхука.');
        }
        $extra = json_decode((string) ($payload['extra'] ?? ''), true);
        $body = ['event' => 'deal.stage', 'run_id' => $run->id] + Placeholders::context($deal, $run->robot->stage) + (is_array($extra) ? ['extra' => $extra] : []);
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json'];
        if (! empty($payload['secret'])) {
            $headers['X-Signature'] = hash_hmac('sha256', $json, (string) $payload['secret']);
        }
        $resp = Http::withHeaders($headers)->timeout(10)->retry(3, 2000)->withBody($json, 'application/json')->post($url);
        if (! $resp->successful()) {
            throw new \RuntimeException('Вебхук ответил '.$resp->status());
        }

        return ['status' => $resp->status()];
    }
}
