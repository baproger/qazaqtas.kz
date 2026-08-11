<?php

use App\Http\Controllers\Site\CartController;
use App\Http\Controllers\Site\CatalogController;
use App\Http\Controllers\Site\CheckoutController;
use App\Http\Controllers\Site\ConfiguratorController;
use App\Http\Controllers\Site\PageController;
use Illuminate\Support\Facades\Route;

/*
 * Публичная витрина QAZAQ TAS. Без авторизации, читает каталог ERP.
 * ERP живёт на своих адресах (/login, /dashboard, /deals…) — они не задеты.
 */

Route::get('/', [PageController::class, 'home'])->name('site.home');
Route::get('/zavod', [PageController::class, 'about'])->name('site.about');
Route::get('/proekty', [PageController::class, 'projects'])->name('site.projects');
Route::get('/kontakty', [PageController::class, 'contacts'])->name('site.contacts');

Route::get('/katalog', [CatalogController::class, 'index'])->name('site.catalog');
Route::get('/katalog/{product}', [CatalogController::class, 'show'])->name('site.product');
Route::get('/api/recent-products', [CatalogController::class, 'recent'])
    ->middleware('throttle:60,1')->name('site.recent');

Route::get('/konfigurator', [ConfiguratorController::class, 'show'])->name('site.configurator');

Route::get('/korzina', [CartController::class, 'show'])->name('site.cart');
Route::post('/korzina/{product}', [CartController::class, 'add'])->middleware('throttle:60,1')->name('site.cart.add');
Route::post('/korzina', [CartController::class, 'addMany'])->middleware('throttle:30,1')->name('site.cart.addMany');
Route::patch('/korzina', [CartController::class, 'update'])->name('site.cart.update');
Route::delete('/korzina/pozitsiya', [CartController::class, 'remove'])->name('site.cart.remove');
Route::delete('/korzina', [CartController::class, 'clear'])->name('site.cart.clear');

// Коммерческое предложение в PDF по составу корзины.
Route::get('/kp', [\App\Http\Controllers\Site\QuotationController::class, 'download'])
    ->middleware('throttle:20,1')->name('site.quotation');

Route::get('/oformlenie', [CheckoutController::class, 'show'])->name('site.checkout');
Route::post('/oformlenie', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('site.checkout.store');
Route::get('/spasibo', [CheckoutController::class, 'thanks'])->name('site.thanks');
