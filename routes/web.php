<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BinLookupController;
use App\Http\Controllers\BonusController;
use App\Http\Controllers\CashBookController;
use App\Http\Controllers\CashReceiptController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogMediaController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyStructureController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\CustomFieldValueController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DdsController;
use App\Http\Controllers\DealAssignmentController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DealStageController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeDebtController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpensesBoardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MyExpensesController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PartnerServiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ProductionPlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectItemController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ServiceModerationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteOrderController;
use App\Http\Controllers\SiteProjectController;
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\StageController;
use App\Http\Controllers\StageRobotController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WorkshopScreenController;
use Illuminate\Support\Facades\Route;

// «/» отдаёт публичную витрину (routes/site.php); ERP начинается с /login.
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// ТВ-экран цеха: без логина, вход по коду (один код = один цех).
Route::get('screen', [WorkshopScreenController::class, 'show'])->name('screen.show');
Route::post('screen', [WorkshopScreenController::class, 'enter'])->middleware('throttle:10,1')->name('screen.enter');
Route::post('screen/leave', [WorkshopScreenController::class, 'leave'])->name('screen.leave');
// «Далее» с ТВ-экрана: двигает этап заказа своего цеха (доступ — код экрана в сессии).
Route::post('screen/projects/{project}/advance', [WorkshopScreenController::class, 'advanceProject'])->middleware('throttle:60,1')->name('screen.advanceProject');
// «Готово» с ТВ-экрана: только с последнего этапа («Отправка») → сделка на Логистику.
Route::post('screen/projects/{project}/complete', [WorkshopScreenController::class, 'completeProject'])->middleware('throttle:30,1')->name('screen.completeProject');

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
    Route::patch('company/switch', [CompanyController::class, 'switch'])->name('company.switch');

    // Users
    // export — до resource: иначе GET users/export сматчится как users/{user}.
    Route::get('users/export', [UserController::class, 'export'])->name('users.export');
    Route::get('users/{user}/contract', [UserController::class, 'contract'])->name('users.contract');
    // Код входа ключевого сотрудника: выдаёт и отзывает только администратор.
    Route::post('users/{user}/access-code', [UserController::class, 'issueAccessCode'])->name('users.accessCode.issue');
    Route::delete('users/{user}/access-code', [UserController::class, 'revokeAccessCode'])->name('users.accessCode.revoke');
    Route::resource('users', UserController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Reference data
    Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('clients', ClientController::class)->only(['index', 'store', 'update', 'destroy']);

    // Deals
    Route::get('deals/overdue', [DealController::class, 'overdue'])->name('deals.overdue');
    // До resource-маршрута: иначе DELETE deals/bulk сматчится как deals/{deal}.
    Route::delete('deals/bulk', [DealController::class, 'bulkDestroy'])->name('deals.bulkDestroy');
    // Нехватка со склада → в «План — факт» без промежуточной заявки.
    Route::post('deals/{deal}/to-production', [DealController::class, 'toProduction'])->name('deals.toProduction');
    Route::get('deals/bin-lookup', [BinLookupController::class, 'binLookup'])
        ->middleware('throttle:30,1')
        ->name('deals.binLookup');
    Route::resource('deals', DealController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::patch('deals/{deal}/stage', [DealStageController::class, 'updateStage'])->name('deals.stage');
    Route::patch('deals/{deal}/advance', [DealStageController::class, 'advance'])->name('deals.advance');
    Route::post('deals/{deal}/to-workshop', [DealStageController::class, 'sendToWorkshop'])->name('deals.toWorkshop');
    Route::patch('deals/{deal}/responsible', [DealAssignmentController::class, 'updateResponsible'])->name('deals.responsible');
    Route::patch('deals/{deal}/foreman', [DealAssignmentController::class, 'updateForeman'])->name('deals.foreman');
    // Ручной % бонуса менеджера по сделке (финансист/админ).
    Route::patch('deals/{deal}/bonus-rate', [DealAssignmentController::class, 'updateBonusRate'])->name('deals.bonusRate');
    Route::patch('deals/{deal}/stage-task', [DealStageController::class, 'completeStageTask'])->name('deals.stageTask');

    // Производство: сменные наряды бригад — выработка в штуках и м².
    Route::get('production', [ProductionController::class, 'index'])->name('production.index');
    Route::post('production/orders', [ProductionController::class, 'store'])->name('production.orders.store');
    Route::patch('production/orders/{order}/confirm', [ProductionController::class, 'confirm'])->name('production.orders.confirm');
    Route::patch('production/orders/{order}/reject', [ProductionController::class, 'reject'])->name('production.orders.reject');
    Route::delete('production/orders/{order}', [ProductionController::class, 'destroy'])->name('production.orders.destroy');

    // «План — факт»: задание цеху на месяц и его выполнение.
    Route::get('production/plans', [ProductionPlanController::class, 'index'])->name('production.plans.index');
    Route::post('production/plans', [ProductionPlanController::class, 'store'])->name('production.plans.store');
    Route::patch('production/plans/{plan}', [ProductionPlanController::class, 'update'])->name('production.plans.update');
    Route::delete('production/plans/{plan}', [ProductionPlanController::class, 'destroy'])->name('production.plans.destroy');
    // Карточка бригады: состав, планы, наряды, начисления за месяц.
    Route::get('production/brigades/{brigade}', [ProductionPlanController::class, 'brigade'])->name('production.brigade');
    Route::post('production/plans/{plan}/output', [ProductionPlanController::class, 'output'])
        ->middleware('throttle:60,1')->name('production.plans.output');
    Route::post('production/brigades', [ProductionController::class, 'storeBrigade'])->name('production.brigades.store');
    Route::patch('production/brigades/{brigade}', [ProductionController::class, 'updateBrigade'])->name('production.brigades.update');
    Route::delete('production/brigades/{brigade}', [ProductionController::class, 'destroyBrigade'])->name('production.brigades.destroy');

    // Склад (приход товара + остатки, у каждой компании свой)
    Route::get('warehouse', [WarehouseController::class, 'index'])->name('warehouse.index');
    Route::post('warehouse/receipt', [WarehouseController::class, 'receipt'])->name('warehouse.receipt');
    // Лента движений готовой продукции: откуда взялся и куда ушёл каждый метр.
    Route::get('warehouse/products/{product}/movements', [WarehouseController::class, 'productMovements'])
        ->middleware('throttle:120,1')->name('warehouse.productMovements');
    Route::put('warehouse/materials/{material}', [WarehouseController::class, 'updateMaterial'])->name('warehouse.materials.update');
    Route::delete('warehouse/materials/{material}', [WarehouseController::class, 'destroyMaterial'])->name('warehouse.materials.destroy');
    Route::put('warehouse/receipts/{receipt}', [WarehouseController::class, 'updateReceipt'])->name('warehouse.receipts.update');
    Route::delete('warehouse/receipts/{receipt}', [WarehouseController::class, 'destroyReceipt'])->name('warehouse.receipts.destroy');

    // Projects
    Route::resource('projects', ProjectController::class)->only(['index', 'show']);
    Route::patch('projects/{project}/stage', [ProjectController::class, 'updateStage'])->name('projects.stage');
    Route::patch('projects/{project}/advance', [ProjectController::class, 'advance'])->name('projects.advance');
    Route::post('projects/{project}/to-act', [ProjectController::class, 'sendToAct'])->name('projects.toAct');
    // Работа по позиции прямо из карточки цеха: «сделал N» и «товар закончен».
    Route::post('projects/{project}/items/{item}/output', [ProjectItemController::class, 'output'])
        ->middleware('throttle:60,1')->name('projects.items.output');
    Route::post('projects/{project}/items/{item}/finish', [ProjectItemController::class, 'finish'])
        ->middleware('throttle:60,1')->name('projects.items.finish');

    // Услуги: кабинет партнёра (только свои, ServicePolicy) и модерация.
    Route::get('partner/services', [PartnerServiceController::class, 'index'])->name('partner.services');
    // Лимит: не больше 5 заявок в час с аккаунта — спам-защита.
    Route::post('partner/services', [PartnerServiceController::class, 'store'])
        ->middleware('throttle:5,60')->name('partner.services.store');
    Route::put('partner/services/{service}', [PartnerServiceController::class, 'update'])
        ->middleware('throttle:10,60')->name('partner.services.update');
    Route::delete('partner/services/{service}', [PartnerServiceController::class, 'destroy'])->name('partner.services.destroy');
    Route::get('moderation/services', [ServiceModerationController::class, 'index'])->name('moderation.services');
    Route::patch('moderation/services/{service}/approve', [ServiceModerationController::class, 'approve'])->name('moderation.services.approve');
    Route::patch('moderation/services/{service}/reject', [ServiceModerationController::class, 'reject'])->name('moderation.services.reject');
    Route::post('moderation/service-categories', [ServiceModerationController::class, 'storeCategory'])->name('moderation.serviceCategories.store');
    Route::put('moderation/service-categories/{category}', [ServiceModerationController::class, 'updateCategory'])->name('moderation.serviceCategories.update');
    Route::delete('moderation/service-categories/{category}', [ServiceModerationController::class, 'destroyCategory'])->name('moderation.serviceCategories.destroy');

    // Tasks (managed inline inside deal/project cards — no standalone board)
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::patch('tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
    Route::patch('tasks/{task}/toggle', [TaskController::class, 'toggle'])->name('tasks.toggle');
    Route::patch('tasks/{task}/autosave', [TaskController::class, 'autosave'])->name('tasks.autosave');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    // Finance
    Route::get('finance', [InvoiceController::class, 'index'])->name('finance.index');
    // Разделы Финансов — отдельными страницами: одна страница со всем сразу
    // прокручивалась на четыре экрана, и найти на ней нужное было нельзя.
    Route::get('finance/invoices', [InvoiceController::class, 'invoices'])->name('finance.invoices');
    Route::get('finance/receipts', [InvoiceController::class, 'receipts'])->name('finance.receipts');
    Route::get('finance/debts', [InvoiceController::class, 'debts'])->name('finance.debts');
    // Корректировка кассы: финансист задаёт фактический остаток наличных.
    Route::post('finance/cash-correction', [InvoiceController::class, 'cashCorrection'])->name('finance.cashCorrection');
    // ДДС — ручная сводка финансиста (без связей с расчётами).
    Route::post('finance/dds', [DdsController::class, 'store'])->name('finance.dds.store');
    Route::put('finance/dds/{entry}', [DdsController::class, 'update'])->name('finance.dds.update');
    Route::delete('finance/dds/{entry}', [DdsController::class, 'destroy'])->name('finance.dds.destroy');
    Route::post('finance/dds-date', [DdsController::class, 'date'])->name('finance.dds.date');
    // Поступления денег (нал/банк) — вводит финансист/админ.
    Route::post('finance/receipts', [CashReceiptController::class, 'store'])->name('finance.receipts.store');
    Route::delete('finance/receipts/{receipt}', [CashReceiptController::class, 'destroy'])->name('finance.receipts.destroy');
    // Задолженности: дебиторка (кто должен нам) / кредиторка (кому должны мы).
    Route::get('settings/screens', [WorkshopScreenController::class, 'admin'])->name('screens.index');
    Route::post('workshop-screens', [WorkshopScreenController::class, 'upsert'])->name('workshopScreens.upsert');
    Route::post('workshop-screens/{screen}/toggle', [WorkshopScreenController::class, 'toggle'])->name('workshopScreens.toggle');
    Route::post('workshop-screens/plan', [WorkshopScreenController::class, 'plan'])->name('workshopScreens.plan');
    Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expenseCategories.store');
    Route::put('expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->name('expenseCategories.update');
    Route::delete('expense-categories/{category}', [ExpenseCategoryController::class, 'destroy'])->name('expenseCategories.destroy');
    Route::post('finance/debts', [DebtController::class, 'store'])->name('finance.debts.store');
    Route::put('finance/debts/{debt}', [DebtController::class, 'update'])->name('finance.debts.update');
    Route::delete('finance/debts/{debt}', [DebtController::class, 'destroy'])->name('finance.debts.destroy');
    Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
    // «Бонусы»: год целиком — начислено по месяцам, выплачено, накоплено.
    Route::get('payroll/bonuses', [BonusController::class, 'index'])->name('bonuses.index');
    Route::post('payroll/bonuses/pay', [BonusController::class, 'pay'])->name('bonuses.pay');
    Route::delete('payroll/bonuses/{payout}', [BonusController::class, 'destroy'])->name('bonuses.destroy');
    Route::post('payroll/adjustments', [PayrollController::class, 'storeAdjustment'])->name('payroll.adjustments.store');
    Route::delete('payroll/adjustments/{adjustment}', [PayrollController::class, 'destroyAdjustment'])->name('payroll.adjustments.destroy');
    Route::patch('payroll/salary/{user}', [PayrollController::class, 'updateSalary'])->name('payroll.salary');
    Route::patch('payroll/hours/{user}', [PayrollController::class, 'updateHours'])->name('payroll.hours');
    Route::patch('payroll/norm', [PayrollController::class, 'updateNorm'])->name('payroll.norm');
    // Долги сотрудников: выдача из кассы, отмена выдачи (бухгалтер/админ).
    Route::post('payroll/debts', [EmployeeDebtController::class, 'store'])->name('payroll.debts.store');
    Route::delete('payroll/debts/{debt}', [EmployeeDebtController::class, 'destroy'])->name('payroll.debts.destroy');
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
    Route::get('cash-book', [CashBookController::class, 'index'])->name('cashBook.index');
    // «Расходы» — рабочее место бухгалтера: очередь на проверку + оплаченные.
    Route::get('expenses-board', [ExpensesBoardController::class, 'index'])->name('expensesBoard.index');
    // «Мои расходы» — личная страница сотрудника: свои заявки и свои выплаты.
    Route::get('my-expenses', [MyExpensesController::class, 'index'])->name('myExpenses.index');

    // Documents
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    // Показ картинки в карточке (inline). Только картинки — см. контроллер.
    Route::get('documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    // Notifications
    // Штамп «что изменилось у меня» для живых обновлений (см. useLive.js).
    Route::get('live/version', [LiveController::class, 'version'])->middleware('throttle:600,1')->name('live.version');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // Locale
    Route::patch('locale', [LocaleController::class, 'update'])->name('locale.update');

    // Каталог сайта ведётся здесь: витрина читает эти же products.
    Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
    Route::post('catalog', [CatalogController::class, 'store'])->name('catalog.store');
    Route::put('catalog/{product:id}', [CatalogController::class, 'update'])->name('catalog.update');
    Route::delete('catalog/{product:id}', [CatalogController::class, 'destroy'])->name('catalog.destroy');
    // Медиа карточки: фото, текстура для 3D, GLB-модель, документы.
    // SEO карточки: чтение, сохранение, генерация (ИИ или шаблон).
    Route::get('catalog/{product:id}/seo', [CatalogController::class, 'seo'])->name('catalog.seo');
    Route::post('catalog/{product:id}/seo', [CatalogController::class, 'saveSeo'])->name('catalog.seo.save');
    Route::post('catalog/{product:id}/seo/generate', [CatalogController::class, 'generateSeo'])->name('catalog.seo.generate');
    Route::post('catalog/{product:id}/translate', [CatalogController::class, 'translateAi'])->name('catalog.translate');
    Route::post('catalog/{product:id}/images', [CatalogMediaController::class, 'storeImages'])->name('catalogMedia.images');
    Route::delete('catalog/{product:id}/images', [CatalogMediaController::class, 'destroyImage'])->name('catalogMedia.imageDestroy');
    Route::post('catalog/{product:id}/images/main', [CatalogMediaController::class, 'makeMainImage'])->name('catalogMedia.imageMain');
    Route::post('catalog/{product:id}/images/color', [CatalogMediaController::class, 'setImageColor'])->name('catalogMedia.imageColor');
    Route::post('catalog/{product:id}/texture', [CatalogMediaController::class, 'setTexture'])->name('catalogMedia.texture');
    Route::post('catalog/{product:id}/model', [CatalogMediaController::class, 'storeModel'])->name('catalogMedia.model');
    Route::delete('catalog/{product:id}/model', [CatalogMediaController::class, 'destroyModel'])->name('catalogMedia.modelDestroy');
    Route::post('catalog/{product:id}/documents', [CatalogMediaController::class, 'storeDocument'])->name('catalogMedia.document');
    Route::delete('catalog/{product:id}/documents', [CatalogMediaController::class, 'destroyDocument'])->name('catalogMedia.documentDestroy');

    Route::get('catalog-categories', [CategoryController::class, 'categories'])->name('catalogCategories.index');
    Route::post('catalog-categories/{category:id}/image', [CategoryController::class, 'storeCategoryImage'])->name('catalogCategories.image');
    Route::delete('catalog-categories/{category:id}/image', [CategoryController::class, 'destroyCategoryImage'])->name('catalogCategories.imageDestroy');
    Route::post('catalog-categories', [CategoryController::class, 'storeCategory'])->name('catalogCategories.store');
    Route::put('catalog-categories/{category:id}', [CategoryController::class, 'updateCategory'])->name('catalogCategories.update');
    Route::delete('catalog-categories/{category:id}', [CategoryController::class, 'destroyCategory'])->name('catalogCategories.destroy');

    // Объекты сайта: реализованные проекты с фото для главной и «Проектов».
    Route::get('site-projects', [SiteProjectController::class, 'index'])->name('siteProjects.index');
    Route::post('site-projects', [SiteProjectController::class, 'store'])->name('siteProjects.store');
    Route::put('site-projects/{project}', [SiteProjectController::class, 'update'])->name('siteProjects.update');
    Route::delete('site-projects/{project}', [SiteProjectController::class, 'destroy'])->name('siteProjects.destroy');
    Route::post('site-projects/{project}/image', [SiteProjectController::class, 'uploadImage'])->name('siteProjects.image');

    // Заказы с сайта → одной кнопкой превращаются в сделку.
    Route::get('site-orders', [SiteOrderController::class, 'index'])->name('siteOrders.index');
    Route::patch('site-orders/{order}', [SiteOrderController::class, 'update'])->name('siteOrders.update');
    Route::post('site-orders/{order}/deal', [SiteOrderController::class, 'convert'])->name('siteOrders.convert');
    Route::delete('site-orders/{order}', [SiteOrderController::class, 'destroy'])->name('siteOrders.destroy');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    // Доступы: матрица «модуль × роль» и личные права сотрудника.
    // Внутренний guard — только admin: право setting.update есть и у других.
    Route::get('settings/access', [AccessController::class, 'index'])->name('access.index');
    Route::put('settings/access', [AccessController::class, 'update'])->name('access.update');
    // Очередь производства: начальник назначает бригаду плану из сделки.
    Route::put('production/plans/{plan}/assign', [ProductionPlanController::class, 'assign'])->name('production.plans.assign');
    Route::put('settings/access/user/{user}', [AccessController::class, 'updateUser'])->name('access.updateUser');
    Route::post('settings/roles', [AccessController::class, 'storeRole'])->name('access.roles.store');
    Route::post('settings/roles/{role}/users', [AccessController::class, 'addToRole'])->name('access.roles.addUsers');
    Route::delete('settings/roles/{role}/users/{user}', [AccessController::class, 'removeFromRole'])->name('access.roles.removeUser');
    Route::put('settings/roles/{role}', [AccessController::class, 'renameRole'])->name('access.roles.rename');
    Route::delete('settings/roles/{role}', [AccessController::class, 'destroyRole'])->name('access.roles.destroy');
    // Структура компании: дерево отделов — оно же граница областей доступа.
    Route::get('structure', [CompanyStructureController::class, 'index'])->name('structure.index');
    Route::post('structure', [CompanyStructureController::class, 'store'])->name('structure.store');
    Route::put('structure/{department}', [CompanyStructureController::class, 'update'])->name('structure.update');
    Route::delete('structure/{department}', [CompanyStructureController::class, 'destroy'])->name('structure.destroy');
    Route::put('structure/assign/{user}', [CompanyStructureController::class, 'assign'])->name('structure.assign');
    // Контент витрины: контакты, филиалы, тарифы доставки, FAQ.
    Route::get('settings/site', [SiteSettingsController::class, 'index'])->name('siteSettings.index');
    Route::put('settings/site', [SiteSettingsController::class, 'update'])->name('siteSettings.update');
    Route::get('settings/stages', [StageController::class, 'index'])->name('stages.index');
    Route::post('settings/stages', [StageController::class, 'store'])->name('stages.store');
    Route::put('settings/stages/{kind}/{id}', [StageController::class, 'update'])->name('stages.update');
    // Порядок воронки задаётся целиком: одно действие и для стрелок, и для перетаскивания.
    Route::patch('settings/stages/{kind}/reorder', [StageController::class, 'reorder'])->name('stages.reorder');
    Route::delete('settings/stages/{kind}/{id}', [StageController::class, 'destroy'])->name('stages.destroy');
    Route::post('settings/stages/{kind}/{id}/duplicate', [StageController::class, 'duplicate'])->name('stages.duplicate');
    // Роботы этапов (автоматизация)
    Route::get('settings/robots', [StageRobotController::class, 'index'])->name('robots.index');
    Route::post('settings/robots', [StageRobotController::class, 'store'])->name('robots.store');
    Route::put('settings/robots/{robot}', [StageRobotController::class, 'update'])->name('robots.update');
    Route::post('settings/robots/{robot}/toggle', [StageRobotController::class, 'toggle'])->name('robots.toggle');
    Route::post('settings/robots/{robot}/duplicate', [StageRobotController::class, 'duplicate'])->name('robots.duplicate');
    Route::delete('settings/robots/{robot}', [StageRobotController::class, 'destroy'])->name('robots.destroy');

    // Custom fields
    // UI translations editor
    Route::get('settings/translations', [TranslationController::class, 'index'])->name('translations.index');
    Route::put('settings/translations', [TranslationController::class, 'update'])->name('translations.update');
    Route::post('settings/translations', [TranslationController::class, 'store'])->name('translations.store');
    Route::delete('settings/translations/{translation}', [TranslationController::class, 'destroy'])->name('translations.destroy');

    Route::get('settings/custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
    Route::post('settings/custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
    Route::put('settings/custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
    Route::delete('settings/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');
    Route::post('custom-field-values', [CustomFieldValueController::class, 'sync'])->name('custom-field-values.sync');

    // Web Push подписка браузера (уведомления чата при закрытой вкладке)
    Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::post('push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1')->name('push.unsubscribe');

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
    Route::get('reports/deals', [ReportController::class, 'deals'])->name('reports.deals');

    // Audit log
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
    // Журнал ошибок — только админ (guard внутри контроллера).
    Route::get('errors', [ErrorLogController::class, 'index'])->name('errors.index');
    Route::patch('errors/{error}/resolve', [ErrorLogController::class, 'resolve'])->name('errors.resolve');
    Route::post('errors/resolve-all', [ErrorLogController::class, 'resolveAll'])->name('errors.resolveAll');
    Route::delete('errors/purge', [ErrorLogController::class, 'purge'])->name('errors.purge');

    // Comments
    Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
    Route::put('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
});

require __DIR__.'/auth.php';
