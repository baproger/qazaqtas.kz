# Testing Patterns

**Analysis Date:** 2026-08-17

## Test Framework

**Runner:**
- PHPUnit 12.5.12
- Config: `phpunit.xml`
- Test suites: Unit (`tests/Unit/`), Feature (`tests/Feature/`)

**Run Commands:**
```bash
php artisan test              # Run all tests
php artisan test --filter=NameTest  # Run specific test class
php artisan test tests/Feature/MyExpensesPageTest.php  # Run single file
composer test                 # Via composer script (clears config first)
```

**CI/CD:**
- GitHub Actions workflow: `.github/workflows/ci.yml`
- Step: `php artisan test` runs on every push to master/main and all PRs
- Extensions: gd (image resize), sqlite3, pdo_sqlite, mbstring, bcmath, intl, zip

## Test File Organization

**Location:**
- Feature tests in `tests/Feature/` (510 tests total)
- Unit tests in `tests/Unit/` (1 test)
- Separate test data fixtures in seeders (`database/seeders/`)

**Naming:**
- Class: `CamelCaseTest.php` (e.g., `MyExpensesPageTest.php`, `CompanyExpenseRequestTest.php`, `ErpInterfaceLocaleTest.php`)
- Method: `test_snake_case_describing_business_logic()` (e.g., `test_page_shows_only_my_own_records()`, `test_pending_is_never_cut_by_the_month_filter()`)

**Structure:**
```
tests/
├── Feature/              # 98 test files, ~510 tests
│   ├── MyExpensesPageTest.php
│   ├── CompanyExpenseRequestTest.php
│   ├── ErpInterfaceLocaleTest.php
│   ├── DealBonusOverrideTest.php
│   └── ...
├── Unit/
└── TestCase.php          # Base class extending Laravel\Foundation\Testing\TestCase
```

## Test Structure

**Suite Organization (from `MyExpensesPageTest.php`):**
```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyExpensesPageTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);
    }

    private function staff(string $role): User { ... }
    private function request(User $author, array $extra = []): Expense { ... }

    public function test_page_shows_only_my_own_records(): void { ... }
}
```

**Patterns:**

1. **RefreshDatabase Trait:**
   - Rolls back and re-runs migrations for each test
   - Uses SQLite `:memory:` database (defined in `phpunit.xml` DB_DATABASE)
   - Ensures test isolation: no test affects another

2. **setUp() Method:**
   - Call `parent::setUp()` first (required)
   - Seed global seeders (`RolePermissionSeeder`, `StageSeeder`)
   - Initialize test fixtures (categories, companies, roles)
   - Runs before every test method

3. **Private Helper Methods:**
   - `staff(string $role): User` — creates a user with a role and assigns to QT company
   - `request(User $author, array $extra = []): Expense` — creates test expense with defaults
   - `payload(array $extra = []): array` — builds HTTP request payload
   - `dictionaryFor(string $locale): array` — extracts i18n data from response props

4. **Assertions Common Pattern:**
   ```php
   $this->actingAs($worker)->get(route('myExpenses.index'))
       ->assertInertia(fn ($page) => $page
           ->component('Finance/MyExpenses')
           ->has('pending', 1)
           ->where('pending.0.id', $mine->id)
           ->where('pending.0.description', 'моя заявка'));
   ```

## Mocking

**Framework:** Mockery (via `laravel/framework` included `mockery/mockery`)

**Patterns:**

1. **Faking Notifications:**
   ```php
   use Illuminate\Support\Facades\Notification;
   
   Notification::fake();
   // ... perform action that should trigger notification ...
   Notification::assertSentTo($user, \App\Notifications\ExpenseThresholdExceeded::class);
   ```
   - See `CompanyExpenseRequestTest.php` for usage

2. **Faking Database Queries:**
   - Not typically done — real SQLite :memory: DB is used instead
   - When needed: `DB::spy()` via Mockery

**What to Mock:**
- External APIs (if used)
- Mail/Notification dispatch
- Time-sensitive operations (use `freeze_time()` from Laravel)

**What NOT to Mock:**
- Database queries — use real :memory: database
- Model relationships — test actual eager/lazy loading
- Business logic in services — test end-to-end

## Fixtures and Factories

**Test Data:**

1. **Model Factories:**
   - Located in `database/factories/`
   - Auto-generated for each Model (e.g., `UserFactory`, `ExpenseFactory`)
   - Usage: `User::factory()->create(['language' => 'kk'])` creates and saves
   - Usage: `User::factory()->make()` builds without saving

2. **Seeders:**
   - `RolePermissionSeeder` (`database/seeders/RolePermissionSeeder.php`):
     - Creates all CRUD permissions (viewAny, view, create, update, delete) for modules
     - Creates roles: admin, director, financist, manager, employee
     - Assigns permissions to each role
     - Must be seeded before any test using roles
   
   - `StageSeeder` (`database/seeders/StageSeeder.php`):
     - Creates deal workflow stages (Заявка → Замер → Договор → ... → Оплата успешно)
     - Creates workshop project stages per city (Шымкент, Алматы, Тараз)
     - Must be seeded for tests involving deal/project progression
     - Creates default company (QT) with code='QT'

3. **Direct Model Creation:**
   - For simple test setup: `ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true])`
   - Avoids factory when only 1–2 fields needed

**Location:**
- Factories: `database/factories/`
- Seeders: `database/seeders/`
- Test class private methods: for reusable patterns specific to one test class

## Assertions

**Inertia Assertions (most common):**

```php
->assertInertia(fn ($page) => $page
    ->component('Finance/MyExpenses')          // Verify component name
    ->has('pending', 1)                        // Assert 'pending' key exists with 1 item
    ->where('pending.0.id', $mine->id)         // Assert nested value
    ->where('pending.0.description', 'моя заявка')
    ->where('totals.pending', 3500)            // Verify calculated totals
);
```

**Database Assertions:**

```php
$this->assertDatabaseHas('expenses', [
    'responsible_user_id' => $worker->id,
    'status' => 'pending',
    'payment_method' => null,
]);

$this->assertDatabaseMissing('ui_translations', ['key' => 'erp.Сохранить']);
```

**Model Assertions:**

```php
$expense = Expense::firstOrFail();
$this->assertSame('pending', $expense->status);
$this->assertNull($expense->payment_method, 'Откуда платить — решает бухгалтер.');
```

**HTTP Response Assertions:**

```php
->assertSessionHasNoErrors()
->assertForbidden()                     // 403
->assertRedirect()
->assertStatus(200)
->assertOk()
```

**Notification Assertions:**

```php
Notification::fake();
// ... trigger action ...
Notification::assertSentTo($user, ExpenseThresholdExceeded::class);
```

**Authorization Assertions:**

```php
$this->actingAs($user)->get(route('profile.edit'))
    ->assertInertia(fn ($page) => $page->where('locale', 'kk'));
```

## Coverage

**Requirements:** No minimum enforced (not checked in CI)

**View Coverage:**
```bash
php artisan test --coverage
php artisan test --coverage --min=80
```

## Test Types

**Unit Tests:**
- Path: `tests/Unit/`
- Scope: Single method, no database, mocked dependencies
- Example: Testing a money calculation formula in isolation
- Currently: Minimal use (1 test total) — most testing is feature-level

**Feature Tests:**
- Path: `tests/Feature/` (510 tests)
- Scope: Full HTTP request → controller → database → response
- Includes: Authorization, validation, database mutations, Inertia props
- Examples:
  - `MyExpensesPageTest::test_page_shows_only_my_own_records()` — verify page isolation
  - `CompanyExpenseRequestTest::test_worker_submits_a_request_that_waits_for_the_accountant()` — verify workflow
  - `ErpInterfaceLocaleTest::test_every_interface_string_has_a_kazakh_translation()` — verify i18n completeness

**E2E Tests:**
- Not used in this project
- No Dusk/browser testing configured

## Common Patterns

**Async Testing:**
- Not applicable (no async operations in Laravel backend)
- Queued jobs tested via `Queue::fake()` if used

**Error Testing:**

```php
public function test_user_without_the_right_is_refused(): void
{
    $nobody = User::factory()->create();
    
    $this->actingAs($nobody)->get(route('myExpenses.index'))
        ->assertForbidden();
}
```

**Testing Financial Boundaries:**

```php
public function test_tiles_match_the_rows(): void
{
    $worker = $this->staff('employee');
    $accountant = $this->staff('financist');

    $this->request($worker, ['amount' => 1000]);
    $this->request($worker, ['amount' => 2500]);
    $this->request($worker, [
        'amount' => 400,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'confirmed_by' => $accountant->id,
        'confirmed_at' => now(),
    ]);
    
    // Server-side totals must match sum of individual rows
    $this->actingAs($worker)->get(route('myExpenses.index'))
        ->assertInertia(fn ($page) => $page
            ->where('totals.pending', 3500)
            ->where('totals.paid', 400));
}
```

**Testing Bilingual Compliance:**

```php
public function test_every_interface_string_has_a_kazakh_translation(): void
{
    $dictionary = require base_path('lang/kk/erp.php');
    $missing = array_values(array_diff($this->stringsUsedInTemplates(), array_keys($dictionary)));

    $this->assertSame([], $missing, 'Эти строки интерфейса остались без казахского перевода.');
}

public function test_owner_override_wins_over_the_shipped_dictionary(): void
{
    UiTranslation::create([
        'key' => 'erp.Сохранить',
        'group' => 'erp',
        'kk' => 'Сақтап қою',
    ]);

    $this->assertSame('Сақтап қою', UiTranslation::map('kk')['erp.Сохранить']);
}
```

**Testing Role-Based Access:**

```php
public function test_manager_can_submit_a_company_request(): void
{
    $manager = $this->staff('manager');

    $this->actingAs($manager)->post(route('expenses.store'), $this->payload())
        ->assertSessionHasNoErrors();

    $this->assertSame('pending', Expense::firstOrFail()->status);
}

public function test_manager_still_cannot_touch_another_managers_deal(): void
{
    $owner = $this->staff('manager');
    $stranger = $this->staff('manager');

    $deal = Deal::create([
        'company_id' => Company::where('code', 'QT')->value('id'),
        'number' => 'QT-900',
        // ...
    ]);
    
    $this->actingAs($stranger)->post(route('expenses.store'), [
        'expenseable_type' => 'deal',
        'expenseable_id' => $deal->id,
        // ...
    ])->assertForbidden();
}
```

**Testing Date Filtering:**

```php
public function test_pending_is_never_cut_by_the_month_filter(): void
{
    $worker = $this->staff('employee');
    $old = $this->request($worker, ['date' => now()->subMonths(3)->toDateString()]);

    $this->actingAs($worker)->get(route('myExpenses.index'))
        ->assertInertia(fn ($page) => $page->has('pending', 1)->where('pending.0.id', $old->id));
}

public function test_paid_requests_are_filtered_by_the_month(): void
{
    $worker = $this->staff('employee');
    $accountant = $this->staff('financist');

    $thisMonth = $this->request($worker, [
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'confirmed_by' => $accountant->id,
        'confirmed_at' => now(),
    ]);
    $old = $this->request($worker, [
        'date' => now()->subMonths(2)->toDateString(),
        'status' => 'confirmed',
        'payment_method' => 'bank',
        'confirmed_by' => $accountant->id,
        'confirmed_at' => now(),
    ]);

    // Current month view
    $this->actingAs($worker)->get(route('myExpenses.index'))
        ->assertInertia(fn ($page) => $page->has('paid', 1)->where('paid.0.id', $thisMonth->id));

    // Past month view via query param
    $this->actingAs($worker)->get(route('myExpenses.index', ['month' => now()->subMonths(2)->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->has('paid', 1)->where('paid.0.id', $old->id));
}
```

## Test Execution Context

**Database:**
- Connection: SQLite (`:memory:` during tests, configured in `phpunit.xml`)
- Migrations: Run automatically via `RefreshDatabase` trait before each test
- No manual database setup needed

**Environment:**
- `phpunit.xml` sets environment: `APP_ENV=testing`
- Cache: array (in-memory, not Redis/Memcached)
- Mail: array driver (no actual sending)
- Queue: sync (execute immediately, not background jobs)
- Session: array driver (in-memory)

**Seeding in Tests:**
- `$this->seed(RolePermissionSeeder::class)` — seeds from the seeder file
- `$this->seed()` with no args — seeds all seeders in natural order
- Called in `setUp()` to ensure every test has fresh seed data

## Key Testing Insights

1. **Server-Side Money Calculations:**
   - Every test that verifies financial data calls totals from response props
   - Never calculates client-side; backend must always be source of truth
   - Example: `->where('totals.pending', 3500)` asserts server calculated correctly

2. **Company Isolation:**
   - Tests verify users can only see their own company's data
   - Staff created with `$user->companies()->attach(Company::where('code', 'QT')->value('id'))`
   - Company code 'QT' is created by `StageSeeder`

3. **Bilingual Completeness:**
   - `ErpInterfaceLocaleTest` ensures every Russian UI string has Kazakh translation
   - Tests run regex on template files to extract `$e()` and `tr()` calls
   - Missing translations found during test, not discovered by users

4. **Policy vs. Permission Safety:**
   - Tests verify both Policy AND role checks work
   - Example: `ExpensePolicy::delete()` checks BOTH role (isAccountant) AND permission (`expense.delete`)
   - Prevents accidental permission grant from bypassing critical rules

---

*Testing analysis: 2026-08-17*
