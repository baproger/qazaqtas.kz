<?php

use App\Http\Controllers\CommentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\CustomFieldValueController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StageController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// «/» отдаёт публичную витрину (routes/site.php); ERP начинается с /login.
Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// ТВ-экран цеха: без логина, вход по коду (один код = один цех).
Route::get('screen', [\App\Http\Controllers\WorkshopScreenController::class, 'show'])->name('screen.show');
Route::post('screen', [\App\Http\Controllers\WorkshopScreenController::class, 'enter'])->middleware('throttle:10,1')->name('screen.enter');
Route::post('screen/leave', [\App\Http\Controllers\WorkshopScreenController::class, 'leave'])->name('screen.leave');
// «Далее» с ТВ-экрана: двигает этап заказа своего цеха (доступ — код экрана в сессии).
Route::post('screen/projects/{project}/advance', [\App\Http\Controllers\WorkshopScreenController::class, 'advanceProject'])->middleware('throttle:60,1')->name('screen.advanceProject');
// «Готово» с ТВ-экрана: только с последнего этапа («Отправка») → сделка на Логистику.
Route::post('screen/projects/{project}/complete', [\App\Http\Controllers\WorkshopScreenController::class, 'completeProject'])->middleware('throttle:30,1')->name('screen.completeProject');

Route::middleware('auth')->group(function () {
    // Single profile page (role-aware card). `update`/`destroy` back the Breeze
    // name/email + password + delete forms; `card.update` saves the card fields.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::put('profile/card/{user}', [ProfileController::class, 'updateCard'])->name('profile.card.update');
    // Every user may set their own avatar; images served (auth-gated) via a route.
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:20,1')->name('profile.avatar');
    Route::get('profile/avatar/{user}', [ProfileController::class, 'avatarShow'])->name('profile.avatar.show');

    // Company switcher
    Route::patch('company/switch', [\App\Http\Controllers\CompanyController::class, 'switch'])->name('company.switch');

    // Users
    // export — до resource: иначе GET users/export сматчится как users/{user}.
    Route::get('users/export', [UserController::class, 'export'])->name('users.export');
    Route::get('users/{user}/contract', [UserController::class, 'contract'])->name('users.contract');
    Route::resource('users', UserController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Reference data
    Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('clients', ClientController::class)->only(['index', 'store', 'update', 'destroy']);

    // Deals
    // Заявки / запросы КП: расчёт маржи до создания сделки.
    Route::get('pre-deals', [\App\Http\Controllers\PreDealController::class, 'index'])->name('preDeals.index');
    Route::post('pre-deals', [\App\Http\Controllers\PreDealController::class, 'store'])->name('preDeals.store');
    // Быстрая проверка № заявки ДО заполнения формы (кнопка «Проверить» у поля).
    Route::get('pre-deals/check-number', [\App\Http\Controllers\PreDealController::class, 'checkNumber'])->middleware('throttle:60,1')->name('preDeals.checkNumber');
    Route::put('pre-deals/{preDeal}', [\App\Http\Controllers\PreDealController::class, 'update'])->name('preDeals.update');
    Route::delete('pre-deals/{preDeal}', [\App\Http\Controllers\PreDealController::class, 'destroy'])->name('preDeals.destroy');
    Route::post('pre-deals/{preDeal}/confirm', [\App\Http\Controllers\PreDealController::class, 'confirm'])->name('preDeals.confirm');
    // Откат случайного «В работу ✓»: сделка удаляется, заявка снова «В работе».
    Route::post('pre-deals/{preDeal}/revert', [\App\Http\Controllers\PreDealController::class, 'revert'])->name('preDeals.revert');
    Route::post('pre-deals/{preDeal}/check/{item}', [\App\Http\Controllers\PreDealController::class, 'check'])->name('preDeals.check');
    Route::post('pre-deal-items', [\App\Http\Controllers\PreDealController::class, 'storeItem'])->name('preDealItems.store');
    Route::put('pre-deal-items/{item}', [\App\Http\Controllers\PreDealController::class, 'updateItem'])->name('preDealItems.update');
    Route::delete('pre-deal-items/{item}', [\App\Http\Controllers\PreDealController::class, 'destroyItem'])->name('preDealItems.destroy');
    Route::get('deals/overdue', [DealController::class, 'overdue'])->name('deals.overdue');
    // До resource-маршрута: иначе DELETE deals/bulk сматчится как deals/{deal}.
    Route::delete('deals/bulk', [DealController::class, 'bulkDestroy'])->name('deals.bulkDestroy');
    Route::get('deals/bin-lookup', [DealController::class, 'binLookup'])
        ->middleware('throttle:30,1')
        ->name('deals.binLookup');
    Route::resource('deals', DealController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::patch('deals/{deal}/stage', [DealController::class, 'updateStage'])->name('deals.stage');
    Route::patch('deals/{deal}/advance', [DealController::class, 'advance'])->name('deals.advance');
    Route::post('deals/{deal}/to-workshop', [DealController::class, 'sendToWorkshop'])->name('deals.toWorkshop');
    Route::patch('deals/{deal}/responsible', [DealController::class, 'updateResponsible'])->name('deals.responsible');
    // Ручной % бонуса менеджера по сделке (финансист/админ).
    Route::patch('deals/{deal}/bonus-rate', [DealController::class, 'updateBonusRate'])->name('deals.bonusRate');
    Route::patch('deals/{deal}/stage-task', [DealController::class, 'completeStageTask'])->name('deals.stageTask');

    // Склад (приход товара + остатки, у каждой компании свой)
    Route::get('warehouse', [\App\Http\Controllers\WarehouseController::class, 'index'])->name('warehouse.index');
    Route::post('warehouse/receipt', [\App\Http\Controllers\WarehouseController::class, 'receipt'])->name('warehouse.receipt');
    Route::put('warehouse/materials/{material}', [\App\Http\Controllers\WarehouseController::class, 'updateMaterial'])->name('warehouse.materials.update');
    Route::delete('warehouse/materials/{material}', [\App\Http\Controllers\WarehouseController::class, 'destroyMaterial'])->name('warehouse.materials.destroy');
    Route::put('warehouse/receipts/{receipt}', [\App\Http\Controllers\WarehouseController::class, 'updateReceipt'])->name('warehouse.receipts.update');
    Route::delete('warehouse/receipts/{receipt}', [\App\Http\Controllers\WarehouseController::class, 'destroyReceipt'])->name('warehouse.receipts.destroy');

    // Projects
    Route::resource('projects', ProjectController::class)->only(['index', 'show']);
    Route::patch('projects/{project}/stage', [ProjectController::class, 'updateStage'])->name('projects.stage');
    Route::patch('projects/{project}/advance', [ProjectController::class, 'advance'])->name('projects.advance');
    Route::post('projects/{project}/to-act', [ProjectController::class, 'sendToAct'])->name('projects.toAct');

    // Tasks (managed inline inside deal/project cards — no standalone board)
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    // Finance
    Route::get('finance', [InvoiceController::class, 'index'])->name('finance.index');
    // Корректировка кассы: финансист задаёт фактический остаток наличных.
    Route::post('finance/cash-correction', [InvoiceController::class, 'cashCorrection'])->name('finance.cashCorrection');
    // ДДС — ручная сводка финансиста (без связей с расчётами).
    Route::post('finance/dds', [\App\Http\Controllers\DdsController::class, 'store'])->name('finance.dds.store');
    Route::put('finance/dds/{entry}', [\App\Http\Controllers\DdsController::class, 'update'])->name('finance.dds.update');
    Route::delete('finance/dds/{entry}', [\App\Http\Controllers\DdsController::class, 'destroy'])->name('finance.dds.destroy');
    Route::post('finance/dds-date', [\App\Http\Controllers\DdsController::class, 'date'])->name('finance.dds.date');
    // Поступления денег (нал/банк) — вводит финансист/админ.
    Route::post('finance/receipts', [\App\Http\Controllers\CashReceiptController::class, 'store'])->name('finance.receipts.store');
    Route::delete('finance/receipts/{receipt}', [\App\Http\Controllers\CashReceiptController::class, 'destroy'])->name('finance.receipts.destroy');
    // Задолженности: дебиторка (кто должен нам) / кредиторка (кому должны мы).
    Route::get('settings/screens', [\App\Http\Controllers\WorkshopScreenController::class, 'admin'])->name('screens.index');
    Route::post('workshop-screens', [\App\Http\Controllers\WorkshopScreenController::class, 'upsert'])->name('workshopScreens.upsert');
    Route::post('workshop-screens/{screen}/toggle', [\App\Http\Controllers\WorkshopScreenController::class, 'toggle'])->name('workshopScreens.toggle');
    Route::post('workshop-screens/plan', [\App\Http\Controllers\WorkshopScreenController::class, 'plan'])->name('workshopScreens.plan');
    Route::post('expense-categories', [\App\Http\Controllers\ExpenseCategoryController::class, 'store'])->name('expenseCategories.store');
    Route::put('expense-categories/{category}', [\App\Http\Controllers\ExpenseCategoryController::class, 'update'])->name('expenseCategories.update');
    Route::delete('expense-categories/{category}', [\App\Http\Controllers\ExpenseCategoryController::class, 'destroy'])->name('expenseCategories.destroy');
    Route::post('finance/debts', [\App\Http\Controllers\DebtController::class, 'store'])->name('finance.debts.store');
    Route::put('finance/debts/{debt}', [\App\Http\Controllers\DebtController::class, 'update'])->name('finance.debts.update');
    Route::delete('finance/debts/{debt}', [\App\Http\Controllers\DebtController::class, 'destroy'])->name('finance.debts.destroy');
    Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('payroll/adjustments', [PayrollController::class, 'storeAdjustment'])->name('payroll.adjustments.store');
    Route::delete('payroll/adjustments/{adjustment}', [PayrollController::class, 'destroyAdjustment'])->name('payroll.adjustments.destroy');
    Route::patch('payroll/salary/{user}', [PayrollController::class, 'updateSalary'])->name('payroll.salary');
    Route::patch('payroll/hours/{user}', [PayrollController::class, 'updateHours'])->name('payroll.hours');
    Route::patch('payroll/norm', [PayrollController::class, 'updateNorm'])->name('payroll.norm');
    // Долги сотрудников: выдача из кассы, отмена выдачи (бухгалтер/админ).
    Route::post('payroll/debts', [\App\Http\Controllers\EmployeeDebtController::class, 'store'])->name('payroll.debts.store');
    Route::delete('payroll/debts/{debt}', [\App\Http\Controllers\EmployeeDebtController::class, 'destroy'])->name('payroll.debts.destroy');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::patch('expenses/{expense}/confirm', [ExpenseController::class, 'confirm'])->name('expenses.confirm');
    Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    // Касса — кассовая книга за день (начало → операции → конец).
    Route::get('cash-book', [\App\Http\Controllers\CashBookController::class, 'index'])->name('cashBook.index');
    // «Расходы» — рабочее место бухгалтера: очередь на проверку + оплаченные.
    Route::get('expenses-board', [\App\Http\Controllers\ExpensesBoardController::class, 'index'])->name('expensesBoard.index');
    // «Мои расходы» — личная страница сотрудника: свои заявки и свои выплаты.
    Route::get('my-expenses', [\App\Http\Controllers\MyExpensesController::class, 'index'])->name('myExpenses.index');

    // Documents
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    // Notifications
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // Locale
    Route::patch('locale', [LocaleController::class, 'update'])->name('locale.update');

    // Каталог сайта ведётся здесь: витрина читает эти же products.
    Route::get('catalog', [\App\Http\Controllers\CatalogController::class, 'index'])->name('catalog.index');
    Route::post('catalog', [\App\Http\Controllers\CatalogController::class, 'store'])->name('catalog.store');
    Route::put('catalog/{product:id}', [\App\Http\Controllers\CatalogController::class, 'update'])->name('catalog.update');
    Route::delete('catalog/{product:id}', [\App\Http\Controllers\CatalogController::class, 'destroy'])->name('catalog.destroy');
    // Медиа карточки: фото, текстура для 3D, GLB-модель, документы.
    Route::post('catalog/{product:id}/images', [\App\Http\Controllers\CatalogMediaController::class, 'storeImages'])->name('catalogMedia.images');
    Route::delete('catalog/{product:id}/images', [\App\Http\Controllers\CatalogMediaController::class, 'destroyImage'])->name('catalogMedia.imageDestroy');
    Route::post('catalog/{product:id}/images/main', [\App\Http\Controllers\CatalogMediaController::class, 'makeMainImage'])->name('catalogMedia.imageMain');
    Route::post('catalog/{product:id}/images/color', [\App\Http\Controllers\CatalogMediaController::class, 'setImageColor'])->name('catalogMedia.imageColor');
    Route::post('catalog/{product:id}/texture', [\App\Http\Controllers\CatalogMediaController::class, 'setTexture'])->name('catalogMedia.texture');
    Route::post('catalog/{product:id}/model', [\App\Http\Controllers\CatalogMediaController::class, 'storeModel'])->name('catalogMedia.model');
    Route::delete('catalog/{product:id}/model', [\App\Http\Controllers\CatalogMediaController::class, 'destroyModel'])->name('catalogMedia.modelDestroy');
    Route::post('catalog/{product:id}/documents', [\App\Http\Controllers\CatalogMediaController::class, 'storeDocument'])->name('catalogMedia.document');
    Route::delete('catalog/{product:id}/documents', [\App\Http\Controllers\CatalogMediaController::class, 'destroyDocument'])->name('catalogMedia.documentDestroy');

    Route::get('catalog-categories', [\App\Http\Controllers\CategoryController::class, 'categories'])->name('catalogCategories.index');
    Route::post('catalog-categories/{category:id}/image', [\App\Http\Controllers\CategoryController::class, 'storeCategoryImage'])->name('catalogCategories.image');
    Route::delete('catalog-categories/{category:id}/image', [\App\Http\Controllers\CategoryController::class, 'destroyCategoryImage'])->name('catalogCategories.imageDestroy');
    Route::post('catalog-categories', [\App\Http\Controllers\CategoryController::class, 'storeCategory'])->name('catalogCategories.store');
    Route::put('catalog-categories/{category:id}', [\App\Http\Controllers\CategoryController::class, 'updateCategory'])->name('catalogCategories.update');
    Route::delete('catalog-categories/{category:id}', [\App\Http\Controllers\CategoryController::class, 'destroyCategory'])->name('catalogCategories.destroy');

    // Объекты сайта: реализованные проекты с фото для главной и «Проектов».
    Route::get('site-projects', [\App\Http\Controllers\SiteProjectController::class, 'index'])->name('siteProjects.index');
    Route::post('site-projects', [\App\Http\Controllers\SiteProjectController::class, 'store'])->name('siteProjects.store');
    Route::put('site-projects/{project}', [\App\Http\Controllers\SiteProjectController::class, 'update'])->name('siteProjects.update');
    Route::delete('site-projects/{project}', [\App\Http\Controllers\SiteProjectController::class, 'destroy'])->name('siteProjects.destroy');
    Route::post('site-projects/{project}/image', [\App\Http\Controllers\SiteProjectController::class, 'uploadImage'])->name('siteProjects.image');

    // Заказы с сайта → одной кнопкой превращаются в сделку.
    Route::get('site-orders', [\App\Http\Controllers\SiteOrderController::class, 'index'])->name('siteOrders.index');
    Route::patch('site-orders/{order}', [\App\Http\Controllers\SiteOrderController::class, 'update'])->name('siteOrders.update');
    Route::post('site-orders/{order}/deal', [\App\Http\Controllers\SiteOrderController::class, 'convert'])->name('siteOrders.convert');
    Route::delete('site-orders/{order}', [\App\Http\Controllers\SiteOrderController::class, 'destroy'])->name('siteOrders.destroy');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    // Контент витрины: контакты, филиалы, тарифы доставки, FAQ.
    Route::get('settings/site', [\App\Http\Controllers\SiteSettingsController::class, 'index'])->name('siteSettings.index');
    Route::put('settings/site', [\App\Http\Controllers\SiteSettingsController::class, 'update'])->name('siteSettings.update');
    Route::get('settings/stages', [StageController::class, 'index'])->name('stages.index');
    Route::post('settings/stages', [StageController::class, 'store'])->name('stages.store');
    Route::put('settings/stages/{kind}/{id}', [StageController::class, 'update'])->name('stages.update');
    // Порядок воронки задаётся целиком: одно действие и для стрелок, и для перетаскивания.
    Route::patch('settings/stages/{kind}/reorder', [StageController::class, 'reorder'])->name('stages.reorder');
    Route::delete('settings/stages/{kind}/{id}', [StageController::class, 'destroy'])->name('stages.destroy');

    // Custom fields
    // UI translations editor
    Route::get('settings/translations', [\App\Http\Controllers\TranslationController::class, 'index'])->name('translations.index');
    Route::put('settings/translations', [\App\Http\Controllers\TranslationController::class, 'update'])->name('translations.update');
    Route::post('settings/translations', [\App\Http\Controllers\TranslationController::class, 'store'])->name('translations.store');
    Route::delete('settings/translations/{translation}', [\App\Http\Controllers\TranslationController::class, 'destroy'])->name('translations.destroy');

    Route::get('settings/custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
    Route::post('settings/custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
    Route::put('settings/custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
    Route::delete('settings/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');
    Route::post('custom-field-values', [CustomFieldValueController::class, 'sync'])->name('custom-field-values.sync');

    // Web Push подписка браузера (уведомления чата при закрытой вкладке)
    Route::post('push/subscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::post('push/unsubscribe', [\App\Http\Controllers\PushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1')->name('push.unsubscribe');

    // Chat
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    // Лёгкий поллинг бейджей/звука — до chat/{chat}-маршрутов.
    Route::get('chat/state', [ChatController::class, 'state'])->middleware('throttle:120,1')->name('chat.state');
    Route::post('chat', [ChatController::class, 'store'])->middleware('throttle:30,1')->name('chat.store');
    // Корзина чатов: вернуть / стереть навсегда (admin/director).
    Route::post('chat/{id}/restore', [ChatController::class, 'restore'])->name('chat.restore');
    Route::delete('chat/{id}/force', [ChatController::class, 'forceDestroy'])->name('chat.force');
    // Участники группы: добавить нового сотрудника / убрать.
    Route::post('chat/{chat}/members', [ChatController::class, 'addMember'])->middleware('throttle:60,1')->name('chat.members.add');
    Route::delete('chat/{chat}/members/{user}', [ChatController::class, 'removeMember'])->name('chat.members.remove');
    Route::get('chat/{chat}/messages', [ChatController::class, 'messages'])->name('chat.messages');
    // Message send accepts file uploads — throttle to curb spam / storage exhaustion.
    Route::post('chat/{chat}/messages', [ChatController::class, 'sendMessage'])->middleware('throttle:120,1')->name('chat.send');
    Route::put('chat/{chat}', [ChatController::class, 'update'])->middleware('throttle:30,1')->name('chat.update');
    Route::delete('chat/{chat}', [ChatController::class, 'destroy'])->name('chat.destroy');
    Route::patch('chat/messages/{message}', [ChatController::class, 'updateMessage'])->middleware('throttle:60,1')->name('chat.messages.update');
    Route::post('chat/messages/{message}/react', [ChatController::class, 'react'])->middleware('throttle:120,1')->name('chat.messages.react');
    Route::post('chat/messages/{message}/pin', [ChatController::class, 'pinMessage'])->middleware('throttle:60,1')->name('chat.messages.pin');
    Route::post('chat/{chat}/read', [ChatController::class, 'markRead'])->middleware('throttle:120,1')->name('chat.read');
    Route::delete('chat/messages/{message}', [ChatController::class, 'destroyMessage'])->name('chat.messages.destroy');
    Route::get('chat/messages/{message}/attachment/{index}', [ChatController::class, 'downloadAttachment'])->name('chat.attachment');
    Route::get('chat/{chat}/attachments', [ChatController::class, 'attachments'])->name('chat.attachments');
    Route::get('chat/{chat}/avatar', [ChatController::class, 'avatar'])->name('chat.avatar');

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    // Реестр сделок (Excel-подобный отчёт, только admin/director)
    Route::get('reports/deals', [\App\Http\Controllers\ReportController::class, 'deals'])->name('reports.deals');

    // Audit log
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

    // Comments
    Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
    Route::put('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
});

require __DIR__.'/auth.php';
