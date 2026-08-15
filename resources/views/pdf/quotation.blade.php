{{-- Коммерческое предложение. Печатный документ: светлый фон, DejaVu Sans —
     единственный встроенный в dompdf шрифт с кириллицей. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1b1e23; line-height: 1.5; }
        .head { border-bottom: 2px solid #8a6d3b; padding-bottom: 10px; }
        .brand { font-size: 20px; font-weight: bold; letter-spacing: 2px; color: #8a6d3b; }
        .muted { color: #6b7078; }
        .right { text-align: right; }
        h1 { font-size: 15px; margin: 18px 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f3f1ec; text-align: left; font-size: 9.5px; text-transform: uppercase;
             letter-spacing: 0.6px; color: #6b7078; padding: 7px 8px; }
        td { padding: 8px; border-bottom: 1px solid #e8e5df; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 12px; width: 46%; margin-left: auto; }
        .totals td { border: 0; padding: 4px 8px; }
        .grand { border-top: 2px solid #8a6d3b; font-size: 13px; font-weight: bold; }
        .note { margin-top: 22px; padding: 10px 12px; background: #f7f5f1; font-size: 9.5px; color: #4a4f57; }
        .foot { margin-top: 18px; font-size: 9.5px; color: #6b7078; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="brand">QAZAQ TAS</div>
                <div class="muted">{{ __('site.quotation.tagline') }}</div>
            </td>
            <td class="right muted">
                {{ $contacts['phone'] }}<br>
                {{ $contacts['email'] }}<br>
                {{ $contacts['hours'] }}
            </td>
        </tr>
    </table>

    <h1>{{ __('site.quotation.title') }} {{ $number }}</h1>
    <div class="muted">
        {{ __('site.quotation.from') }} {{ $date->format('d.m.Y') }} · {{ __('site.quotation.valid_until') }} {{ $validUntil->format('d.m.Y') }}
        @if(!empty($customer['name'])) · {{ __('site.quotation.for') }} {{ $customer['name'] }}@endif
        @if(!empty($customer['phone'])) · {{ $customer['phone'] }}@endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 26px">№</th>
                <th>{{ __('site.quotation.col_name') }}</th>
                <th class="num">{{ __('site.quotation.col_qty') }}</th>
                <th class="num">{{ __('site.quotation.col_price') }}</th>
                <th class="num">{{ __('site.quotation.col_sum') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cart['items'] as $i => $item)
                <tr>
                    <td class="muted">{{ $i + 1 }}</td>
                    <td>
                        {{ $item['name'] }}
                        @if($item['color'])<br><span class="muted">{{ __('site.quotation.color') }} {{ $item['color'] }}</span>@endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format($item['quantity'], 2, '.', ' '), '0'), '.') }} {{ $item['unit'] }}</td>
                    <td class="num">{{ number_format($item['price'], 0, '.', ' ') }} ₸</td>
                    <td class="num">{{ number_format($item['sum'], 0, '.', ' ') }} ₸</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">{{ __('site.quotation.materials') }}</td>
            <td class="num">{{ number_format($cart['total'], 0, '.', ' ') }} ₸</td>
        </tr>
        <tr class="grand">
            <td>{{ __('site.quotation.total') }}</td>
            <td class="num">{{ number_format($cart['total'], 0, '.', ' ') }} ₸</td>
        </tr>
    </table>

    <div class="note">
        {{ __('site.quotation.note', ['tax' => $taxPercent]) }}
    </div>

    <div class="foot">
        <b>{{ __('site.quotation.production') }}</b>
        @foreach($branches as $b){{ $b['city'] }}, {{ $b['address'] }}@if(!$loop->last) · @endif @endforeach
    </div>
</body>
</html>
