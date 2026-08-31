<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Базовые security-заголовки: запрет MIME-sniffing, запрет встраивания
 * в чужие iframe (кликджекинг), скупой Referrer, отключение ненужных
 * браузерных API; HSTS — только когда сайт уже открыт по HTTPS.
 */
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Nonce на каждый запрос: @vite подхватывает его сам, @routes (Ziggy)
        // получает его в app.blade.php. Инлайн-скрипты без nonce блокируются.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // DENY, а не SAMEORIGIN: ни витрина, ни ERP себя нигде не встраивают.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $response->headers->set('Content-Security-Policy', $this->policy($nonce));

        return $response;
    }

    /**
     * Блокирующая политика. Скрипты — только свои сборки и инлайны с nonce
     * этого запроса (Ziggy): даже найденный XSS не выполнит чужой скрипт.
     * В style-src остаётся 'unsafe-inline' — Vue выставляет стили атрибутом
     * style; добавить туда nonce нельзя, браузер тогда игнорирует
     * 'unsafe-inline' и разметка рассыпается.
     */
    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            // 'unsafe-inline' нужен, пока Vue выставляет стили атрибутом style.
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            // data: — зерно фона и превью; blob: — выгрузка PDF-КП.
            "img-src 'self' data: blob:",
            "script-src 'self' 'nonce-{$nonce}'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
