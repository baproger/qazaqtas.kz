<?php

use App\Http\Controllers\Site\CartController;
use App\Http\Controllers\Site\CatalogController;
use App\Http\Controllers\Site\CheckoutController;
use App\Http\Controllers\Site\ConfiguratorController;
use App\Http\Controllers\Site\PageController;
use App\Support\Locales;
use Illuminate\Support\Facades\Route;

/*
 * Публичная витрина QAZAQ TAS. Без авторизации, читает каталог ERP.
 * ERP живёт на своих адресах (/login, /dashboard, /deals…) — они не задеты.
 *
 * Каждый маршрут регистрируется на всех языках: без префикса (`site.*`) и по
 * одному разу под каждым языком (`kk.site.*`, `ru.site.*`). Основной язык
 * задаётся настройкой ERP, а не списком здесь: маршруты кэшируются, и если бы
 * набор адресов зависел от настройки, смена языка в админке требовала бы
 * `route:clear` на проде. Поэтому адреса постоянны, а SetLocale уводит с
 * префикса основного языка на канонический адрес без префикса.
 */

$routes = function (): void {
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::get('/zavod', [PageController::class, 'about'])->name('about');
    Route::get('/proekty', [PageController::class, 'projects'])->name('projects');
    Route::get('/kontakty', [PageController::class, 'contacts'])->name('contacts');

    Route::get('/katalog', [CatalogController::class, 'index'])->name('catalog');
    Route::get('/katalog/{product}', [CatalogController::class, 'show'])->name('product');
    Route::get('/api/recent-products', [CatalogController::class, 'recent'])
        ->middleware('throttle:60,1')->name('recent');

    Route::get('/konfigurator', [ConfiguratorController::class, 'show'])->name('configurator');

    Route::get('/korzina', [CartController::class, 'show'])->name('cart');
    Route::post('/korzina/{product}', [CartController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
    Route::post('/korzina', [CartController::class, 'addMany'])->middleware('throttle:30,1')->name('cart.addMany');
    Route::patch('/korzina', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/korzina/pozitsiya', [CartController::class, 'remove'])->name('cart.remove');
    Route::delete('/korzina', [CartController::class, 'clear'])->name('cart.clear');

    // Коммерческое предложение в PDF по составу корзины.
    Route::get('/kp', [\App\Http\Controllers\Site\QuotationController::class, 'download'])
        ->middleware('throttle:20,1')->name('quotation');

    Route::get('/oformlenie', [CheckoutController::class, 'show'])->name('checkout');
    Route::post('/oformlenie', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');
    Route::get('/spasibo', [CheckoutController::class, 'thanks'])->name('thanks');
};

// Адреса без префикса — язык по умолчанию из настроек.
Route::name('site.')->group($routes);

// Языковые копии. Префикс основного языка существует, но SetLocale уводит с
// него на канонический адрес — дублей в индексе не будет.
foreach (Locales::ALL as $locale) {
    Route::prefix($locale)->name("$locale.site.")->group($routes);
}
