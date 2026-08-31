<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" type="image/png" href="/logo-mark.png">
        <link rel="apple-touch-icon" href="/logo-mark.png">

        {{-- Языковые версии страницы: только для витрины (в ERP язык берётся
             из карточки сотрудника, а не из адреса, и alternates пуст). --}}
        @php($alternates = \App\Support\Locales::alternates(request()))
        @if ($alternates)
            <link rel="canonical" href="{{ $alternates[app()->getLocale()] }}">
            @foreach ($alternates as $altLocale => $altUrl)
                <link rel="alternate" hreflang="{{ $altLocale }}" href="{{ $altUrl }}">
            @endforeach
            <link rel="alternate" hreflang="x-default" href="{{ $alternates[\App\Support\Locales::default()] }}">
        @endif

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700|figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
