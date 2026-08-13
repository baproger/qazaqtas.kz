<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\CartService;
use App\Support\SiteContent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Коммерческое предложение в PDF по составу корзины. Цены и позиции берутся
 * из каталога ERP на момент формирования, поэтому КП всегда совпадает
 * с тем, что клиент видит на сайте.
 */
class QuotationController extends Controller
{
    public function __construct(private CartService $cart) {}

    public function download(Request $request): Response|RedirectResponse
    {
        $cart = $this->cart->contents();
        if (! $cart['items']) {
            return redirect()->route(\App\Support\Locales::routeName('site.cart', app()->getLocale()))
                ->with('error', __('site.flash.quotation_empty'));
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $taxPercent = (float) Setting::get('tax_percent', 3);
        $number = 'КП-'.now()->format('Ymd-His');

        $pdf = Pdf::loadView('pdf.quotation', [
            'number' => $number,
            'date' => now(),
            'validUntil' => now()->addDays(14),
            'cart' => $cart,
            'customer' => $data,
            'contacts' => SiteContent::contacts(),
            'branches' => SiteContent::branches(),
            'taxPercent' => $taxPercent,
        ])->setPaper('a4');

        return $pdf->download($number.'.pdf');
    }
}
