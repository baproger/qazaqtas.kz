<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" type="image/png" href="/logo-mark.png">
        <link rel="apple-touch-icon" href="/logo-mark.png">

        {{-- Canonical и hreflang рендерит SiteLayout через Inertia Head:
             blade-версия «застывала» на первой странице при SPA-переходах. --}}

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700|figtree:400,500,600&display=swap" rel="stylesheet" />

        {{-- Тема ERP до первой отрисовки: без этого тёмная тема мигала бы
             белым. Скрипт инлайновый, поэтому подписан CSP-nonce. --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            try { if (localStorage.getItem('erp.theme') === 'dark') document.documentElement.classList.add('dark'); } catch (e) {}
        </script>

        <!-- Scripts -->
        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
