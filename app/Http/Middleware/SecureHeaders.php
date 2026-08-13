<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // DENY, а не SAMEORIGIN: ни витрина, ни ERP себя нигде не встраивают.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $response->headers->set('Content-Security-Policy-Report-Only', $this->policy());

        return $response;
    }

    /**
     * Политика пока только собирает нарушения в консоль браузера, ничего не
     * блокируя: Vue раздаёт инлайн-стили, зерно фона лежит в data:-URI, а
     * шрифты приходят с CDN. Переводить в блокирующий режим — отдельным
     * решением, после недели наблюдения за отчётами.
     */
    private function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            // 'unsafe-inline' нужен, пока Vue выставляет стили атрибутом style.
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            // data: — зерно фона и превью; blob: — выгрузка PDF-КП.
            "img-src 'self' data: blob:",
            "script-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
