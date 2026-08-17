# Coding Conventions

**Analysis Date:** 2026-08-17

## Naming Patterns

**Files:**
- Controller files: `CamelCaseController.php` (e.g., `app/Http/Controllers/ExpenseController.php`, `app/Http/Controllers/MyExpensesController.php`)
- Model files: singular `CamelCase.php` (e.g., `app/Models/Expense.php`, `app/Models/DealStage.php`)
- Service files: `CamelCaseService.php` (e.g., `app/Services/FinanceService.php`)
- Policy files: `CamelCasePolicy.php` (e.g., `app/Policies/ExpensePolicy.php`)
- Seeder files: `CamelCaseSeeder.php` (e.g., `database/seeders/RolePermissionSeeder.php`)
- Test files: `CamelCaseTest.php` in `tests/Feature/` (e.g., `tests/Feature/MyExpensesPageTest.php`, `tests/Feature/CompanyExpenseRequestTest.php`)

**Functions/Methods:**
- camelCase for all methods
- Private helper methods prefixed with underscore in controllers (e.g., `_assertOwnership()`, `_assertCanSeeReceipt()`, `_storeReceipt()`, `_resolve()`)
- Descriptive test method names starting with `test_` using snake_case describing business logic (e.g., `test_page_shows_only_my_own_records()`, `test_pending_is_never_cut_by_the_month_filter()`)

**Variables:**
- camelCase for all variables
- Money amounts stored as floats, cast and calculated as floats (e.g., `(float) $deal->budget`, `(float) $pay->sum('amount')`)
- Boolean flags use clear affirmative names (e.g., `$isAccountant`, `$isActive`)

**Types:**
- Full namespace imports for Models (e.g., `App\Models\Expense`, `App\Models\User`)
- Type hints on all function parameters and returns
- Nullable types use `?ClassName` pattern (e.g., `?int $companyId`, `?Model $entity`)

## Code Style

**Formatting:**
- Tool: Laravel Pint
- Run: `composer exec pint` (via composer.json scripts)
- Follows PSR-12 extended by Laravel standards

**Linting:**
- Tool: Laravel Pint (PHP only)
- No ESLint or Prettier configured for Vue files — formatting is manual/by convention
- Check via CI: `.github/workflows/ci.yml` runs Pint as part of pre-test validation

## Import Organization

**Order:**
1. PHP namespace declaration
2. Namespace-qualified imports (`use`)
   - Models first
   - Services second
   - Facades third
   - Exceptions and other classes last
3. No blank line between `<?php` and namespace

**Pattern in `app/Services/FinanceService.php`:**
```php
<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
```

## Error Handling

**Patterns:**
- Use `abort()` and `abort_unless()` for authorization failures (returns 403)
- Use `abort_unless()` for permission guards: `abort_unless($user->hasRole('admin'), 403)`
- Custom error messages on abort: `abort_unless($condition, 403, 'Кто-то это может видеть только вот так.')`
- Use `throw ValidationException::withMessages([...])` for form validation errors (`app/Http/Controllers/ExpenseController.php` lines 142-149)
- Services raise exceptions, controllers catch and handle
- All exceptions in Russian (matching user-visible language)

**Money-Related Safety:**
- Financial controls are duplicated in both Policies AND role checks (see `app/Policies/ExpensePolicy.php` lines 8-21)
- Policy denials are independent of permission system: "право можно вернуть через админку по неосторожности, а деньги ошибок не прощают"
- Critical rule: `isAccountant($user): bool` checks `$user->hasAnyRole(['admin', 'financist'])` — no permission fallback

**Company Isolation:**
- Use `$user->worksInCompany($companyId)` to validate user-company membership
- Never trust user-provided `company_id` — always validate against authenticated user's assigned companies
- See `app/Http/Controllers/ExpenseController.php` lines 18-35 for pattern

## Logging

**Framework:** console via `\Illuminate\Support\Facades\Log` or direct `echo` in CLI contexts

**Patterns:**
- Log financial transactions via `Log::channel('daily')` (implicit via Laravel config)
- Use database audit tables for financial record-keeping (not just logs)
- CLI commands use direct output: `$this->info()`, `$this->warn()`

## Comments

**When to Comment:**
- **Required:** Comments explain WHY business logic exists, not WHAT the code does
- Comments are in Russian and load-bearing for understanding financial/compliance rules
- Example from `app/Services/FinanceService.php` (lines 13-21):
  ```php
  /**
   * Остатки денег (касса/банк) = платежи по счетам + поступления
   * (cash_receipts) − подтверждённые расходы, всё по способу оплаты.
   *
   * КАССА (наличные) — ЕДИНАЯ на весь холдинг: физически деньги в одной
   * кассе, расход налом ЛЮБОЙ фирмы уменьшает общий остаток.
   * БАНК — раздельно по компаниям (у каждой фирмы свои счета).
   * Показывается на Финансах и бухгалтеру в форме расхода («доступно N»).
   */
  ```
- Comments above private helper methods explain decision points:
  ```php
  // Чек хранится вне public-корня (storage/app/private), как и документы;
  // фото с телефона по дороге ужимается до веб-размера.
  ```

**JSDoc/TSDoc:**
- Not enforced for Vue files
- Optional for complex functions
- Use `@param`, `@return`, `@throws` when present

## Function Design

**Size:** 
- Keep functions under 30 lines for readability
- Services like `FinanceService` methods are 15–25 lines
- Controllers extract complex logic to private methods

**Parameters:**
- Use named parameter pattern via array unpacking for optionals
- Example: `$entity = $this->resolve($request->input('expenseable_type', 'deal'), ...)`
- Never pass more than 3 required parameters — refactor to object/DTO

**Return Values:**
- Always declare return type (`: bool`, `: array`, `: Response`)
- Use nullable returns for optional results: `: ?Model`
- Money always returns `float`

## Module Design

**Exports:**
- Controllers: export `public function index(), store(), update(), destroy()` following REST
- Services: export only public methods needed by controllers
- Models: export accessors/mutators as public methods

**Barrel Files:**
- Not used in this project — each controller imports from its full path

## Database Queries

**Money Calculations:**
- NEVER compute money on client side — all totals calculated server-side
- Comment why: "Плитки считает сервер: клиент только показывает (§C.5)" (`app/Http/Controllers/MyExpensesController.php` line 70)
- Use `(float)` cast for all money arithmetic to avoid PHP integer precision issues
- Example from `FinanceService::methodBalance()`:
  ```php
  $paySum = (float) $pay->sum('amount');
  $recSum = (float) \App\Models\CashReceipt::query()...->sum('amount');
  ```

**Scopes (Query Builders):**
- Use public methods on Services, not Eloquent scopes
- Reason: Scopes in multiple places cause divergence ("разошедшийся скоуп означает страницы с разными цифрами")
- See `FinanceService::scopeCompanyExpenses()` comment (lines 30-36) — logic centralized in one service method
- All queries selecting expenses for a company use the same scope to prevent inconsistency

## Inertia Props

**Pattern:**
- Controllers return `Inertia::render('Component/Name', [props])`
- Component name: `PascalCase` path matching Vue file location (e.g., `'Finance/MyExpenses'` → `resources/js/Pages/Finance/MyExpenses.vue`)
- Props are PHP arrays converted to JSON
- Server-side computed values only (e.g., `totals`, `categories`)

**Example from `MyExpensesController::index()`:**
```php
return Inertia::render('Finance/MyExpenses', [
    'pending' => $pending,
    'paid' => $paid,
    'payouts' => $payouts,
    'totals' => [
        'pending' => round((float) $pending->sum('amount'), 2),
        'paid' => round((float) $paid->sum('amount'), 2),
        'payouts' => round((float) $payouts->sum('amount'), 2),
    ],
    'month' => $month,
    'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
]);
```

## Bilingual Interface (Russian ↔ Kazakh)

**Core Rule:**
- Every UI string uses `$e('Русский текст')` (in Blade) or `tr('Русский текст')` (in Vue)
- Dictionary key = Russian text itself (gettext-style)
- Kazakh translation lives in `lang/kk/erp.php` with Russian key

**Example from `lang/kk/erp.php`:**
```php
return [
    ' (по дате договора)' => ' (шарт күні бойынша)',
    '+ Дело' => '+ Іс',
    'Сохранить' => 'Сақтау',
    // ...
];
```

**Testing the Bilingual Rule:**
- Test file: `tests/Feature/ErpInterfaceLocaleTest.php`
- Critical assertion: Russian interface NEVER shows Kazakh text (fallback to key only) — see `test_russian_interface_never_falls_back_to_kazakh()`
- Every Kazakh translation must have a corresponding Russian string in the interface
- Test `test_every_interface_string_has_a_kazakh_translation()` ensures no orphans

**Translations via Admin UI:**
- Translations edited in admin panel → `UiTranslation` model
- Override model stores only changes; cleared overrides fall back to shipped dictionary
- See test `test_owner_override_wins_over_the_shipped_dictionary()`

## Tailwind Design Conventions

**Reference:** `FINANCE-DESIGN.md` documents design system

**Key Patterns:**
- Utility-first CSS with Tailwind v3.2.1
- Custom colors/opacity defined in `tailwind.config.js`
- Form styling via `@tailwindcss/forms` plugin
- Financial components use specific color palettes for amounts (green=profit, red=loss, yellow=warning)
- Responsive breakpoints: mobile-first, then `md:`, `lg:`, `xl:`

**No CSS Modules/Scoped Styles:**
- All styles via Tailwind classes in Vue templates
- Global CSS in `resources/css/app.css` imported by `app.blade.php`

## Authorization & Policies

**Pattern:**
- Use `$this->authorize('action', $model)` in controller
- Policies live in `app/Policies/CamelCasePolicy.php`
- Policy methods: `viewAny(), view(), create(), update(), delete()`

**Role-Based Access (in Policy):**
- Check permission first: `if (! $u->can('module.action')) { return false; }`
- Then role-based logic if needed (e.g., accountant-only actions)
- See `app/Policies/ExpensePolicy.php` for safety duplicating role checks:
  ```php
  private function isAccountant(User $user): bool
  {
      return $user->hasAnyRole(['admin', 'financist']);
  }
  
  public function delete(User $u, Expense $e): bool
  {
      return $this->isAccountant($u) && $u->can('expense.delete');
  }
  ```

---

*Convention analysis: 2026-08-17*
