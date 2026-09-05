<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" type="image/png" href="/logo-mark.png">
        <link rel="apple-touch-icon" href="/logo-mark.png">

        {{-- Hreflang/canonical для краулеров без JS: корректны для ПЕРВОГО
             запроса. SiteLayout при монтировании удаляет их (data-ssr-seo)
             и дальше ведёт эти теги сам через Inertia Head — иначе при
             SPA-переходах они застывали от первой страницы. --}}
        @php($alternates = \App\Support\Locales::alternates(request()))
        @if ($alternates)
            <link data-ssr-seo rel="canonical" href="{{ $alternates[app()->getLocale()] }}">
            @foreach ($alternates as $altLocale => $altUrl)
                <link data-ssr-seo rel="alternate" hreflang="{{ $altLocale }}" href="{{ $altUrl }}">
            @endforeach
            <link data-ssr-seo rel="alternate" hreflang="x-default" href="{{ $alternates[\App\Support\Locales::default()] }}">
        @endif

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
