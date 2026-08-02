<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push: уведомления в браузер даже при закрытой вкладке ERP (как WhatsApp).
 * VAPID-ключи — в .env (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY); нет ключей —
 * отправка тихо пропускается. Протухшие подписки (410/404) удаляются.
 */
class PushService
{
    /** @param  iterable<int>  $userIds */
    public function sendToUsers(iterable $userIds, string $title, string $body, string $url = '/chat'): void
    {
        $public = (string) config('services.webpush.public_key');
        $private = (string) config('services.webpush.private_key');
        if ($public === '' || $private === '') {
            return;
        }

        $subs = PushSubscription::whereIn('user_id', collect($userIds)->all())->get();
        if ($subs->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('app.url', 'https://erp.qazaqtas.kz'),
                'publicKey' => $public,
                'privateKey' => $private,
            ]]);
            // ERP за медленными сетями: не ждать долго, уведомление не критично.
            $webPush->setDefaultOptions(['TTL' => 3600, 'urgency' => 'high']);

            $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE);
            foreach ($subs as $sub) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                ]), $payload);
            }

            foreach ($webPush->flush() as $report) {
                // Браузер отписался/подписка протухла — чистим, чтобы не долбить зря.
                if (! $report->isSuccess() && in_array($report->getResponse()?->getStatusCode(), [404, 410], true)) {
                    PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                }
            }
        } catch (\Throwable $e) {
            // Пуш — best effort: сбой отправки не должен ломать отправку сообщения.
            Log::warning('WebPush send failed: '.$e->getMessage());
        }
    }
}
