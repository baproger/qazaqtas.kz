<!-- refreshed: 2026-08-17 -->
# Architecture

**Analysis Date:** 2026-08-17

## System Overview

QAZAQ TAS is a Laravel 13 monolith using Inertia.js + Vue 3 serving two separate interfaces from one codebase:

1. **ERP (Internal):** Multi-user application at `/login`, `/dashboard`, `/deals`, etc. (`routes/web.php`)
2. **Public Storefront:** Marketing site + e-commerce at `/`, `/katalog`, `/korzina`, etc. (`routes/site.php`)

Both share models, services, and the database; routing and middleware keep them isolated.

```text
┌─────────────────────────────────────────────────────────────────┐
│                     HTTP Request (Inertia)                       │
├──────────────────────────────────────────────────────────────────┤
│  Middleware: Auth + SetCurrentCompany + SetLocale + HandleInertia│
└──────────────────┬───────────────────────────────────────────────┘
                   │
         ┌─────────▼─────────┐
         │    Controllers    │ (app/Http/Controllers)
         │   DealController  │ - Handle HTTP requests
         │  ProjectController│ - Call services + return Inertia response
         │  FinanceController│
         └─────────┬─────────┘
                   │
         ┌─────────▼─────────────────────────┐
         │      Services                     │ (app/Services)
         │  FinanceService ◄────────────────────┤ Handle business logic
         │  PayrollService   │                │
         │  ProjectService   │                │
         │  StageTransitionService           │
         │  EmployeeDebtService              │
         │  MediaService                     │
         └─────────┬─────────────────────────┘
                   │
         ┌─────────▼──────────────────────┐
         │   Models (Eloquent ORM)        │ (app/Models)
         │  Deal ◄──┐                     │
         │  Project ├─── morphMany ────────── Expense, Invoice, Task
         │  User    │                     │
         │  Company │                     │
         └─────────┬──────────────────────┘
                   │
         ┌─────────▼──────────────────────┐
         │     MySQL Database             │
         │  (companies, deals, projects,  │
         │   expenses, invoices, users)   │
         └────────────────────────────────┘
                   │
         ┌─────────▼──────────────────────┐
         │   Vue 3 Pages (Inertia)        │ (resources/js/Pages)
         │  AppLayout + Sidebar Menu      │
         │  FinanceLayout + Tab Navigation│
         │  SiteLayout (no auth)          │
         └────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| **Routes (ERP)** | Define authenticated endpoints, route to controllers | `routes/web.php` |
| **Routes (Site)** | Define public storefront, multi-language support | `routes/site.php` |
| **SetCurrentCompany** | Isolate company-scoped data by session | `app/Http/Middleware/SetCurrentCompany.php` |
| **SetLocale** | Resolve user language: profile → session → setting | `app/Http/Middleware/SetLocale.php` |
| **HandleInertiaRequests** | Share auth, notifications, i18n, site data with all pages | `app/Http/Middleware/HandleInertiaRequests.php` |
| **Controllers** | Parse request, call services, return Inertia response | `app/Http/Controllers/{Name}Controller.php` |
| **Services** | Business logic: calculations, state transitions, notifications | `app/Services/{Name}Service.php` |
| **Models** | Data entities, relationships, soft deletes, audit | `app/Models/{Name}.php` |
| **Policies** | Authorization: who can view/update/delete what | `app/Policies/{Name}Policy.php` |
| **Vue Pages** | Render request data as interactive UI | `resources/js/Pages/{Page}.vue` |
| **Layouts** | Wrapper for pages: AppLayout (menu), FinanceLayout (tabs), SiteLayout (no menu) | `resources/js/Layouts/{Name}Layout.vue` |

## Pattern Overview

**Overall:** Layered MVC with services and polymorphic models.

**Key Characteristics:**
- **Inertia.js:** Server-driven rendering with Vue 3 frontend. No API layer; controllers return `Inertia::render('Page', $props)`.
- **Company Isolation:** Each request has `CurrentCompany::id()` in session; queries scoped via middleware.
- **Multi-language:** Base language (Kazakh) + Russian; language set via `users.language` → session → setting.
- **Polymorphic Finances:** Expenses/invoices belong to Deal OR Project via `morphMany` (shared logic).
- **Stage Timestamps:** Deal and Project track entry/exit time per stage for analytics (deal_stage_logs, project_stage_logs).
- **Soft Deletes:** Deals/projects marked deleted; numbers renamed to free up slots.

## Layers

**Routing Layer:**
- Purpose: Map URLs to controllers; separate ERP and site logic.
- Location: `routes/web.php` (ERP), `routes/site.php` (site)
- Shared routes: Auth (`routes/auth.php`), console commands (`routes/console.php`)
- Depends on: Controllers
- Used by: HTTP clients

**Middleware Layer:**
- Purpose: Request preprocessing; enforce company/language/auth context.
- Location: `app/Http/Middleware/`
- Key classes:
  - `SetCurrentCompany` — Defaults to user's first company; allows "all companies" for financist/admin.
  - `SetLocale` — Reads user language preference or URL prefix.
  - `HandleInertiaRequests` — Shares auth, notifications, i18n, site metadata with Vue.
  - `SecureHeaders` — CSP, HSTS, X-Frame-Options.
- Depends on: Models, Support (CurrentCompany, Locales)
- Used by: Controllers

**Controller Layer:**
- Purpose: Handle HTTP requests; orchestrate services; return Inertia responses.
- Location: `app/Http/Controllers/`
- Responsibility: Validate input, call services, return data for Vue.
- Key controllers:
  - `DealController` — CRUD deals, advance stages, send to workshop.
  - `ProjectController` — View projects (workshop orders), advance stages.
  - `FinanceController` (InvoiceController, PaymentController, ExpenseController) — Finance operations.
  - `PayrollController` — Salary, bonuses, adjustments.
  - `PreDealController` — Quote requests with margin auto-calc.
  - Site controllers (`Site/CatalogController`, `Site/CartController`, etc.) — Public pages.
- Depends on: Services, Models, Requests (validation)
- Used by: Routes

**Service Layer:**
- Purpose: Encapsulate business logic; reusable across controllers.
- Location: `app/Services/`
- Key services:
  - `FinanceService` — Company balances, invoices, debtors.
  - `PayrollService` — Salary calc, bonuses (stepped by margin + custom %), employee debts.
  - `ProjectService` — Workshop logic: complete order and return to deal.
  - `StageTransitionService` — Guard stage transitions; run gate tasks; send notifications.
  - `EmployeeDebtService` — Debt creation and automated monthly charging.
  - `MediaService` — Image resize, WebP conversion, 3D model storage.
  - `CatalogService` — Scene assets for 3D, product translations.
- Depends on: Models, Notifications, external services (image libs)
- Used by: Controllers

**Model/Data Layer:**
- Purpose: Data entities and relationships; soft deletes; audit trail.
- Location: `app/Models/`
- Key relationships:
  - `Deal` — Belongs to Company, has Invoices/Expenses/Tasks/Comments (all `morphMany`)
  - `Project` (Workshop order) — Belongs to Deal, has same morph relations
  - `Expense` — `morphTo` Deal or Project; tracks payment method and confirm status
  - `Invoice` — `morphTo` Deal or Project; tracks payment status
  - `User` — Roles/permissions (spatie/laravel-permission), attached to companies
  - `Company` — Isolates all data; multiple per system (currently one: QT)
  - Translation tables — Cascade translations for Products, Categories, SiteProjects
- Concerns:
  - `Auditable` — Track who changed what (AuditLog)
  - `HasTranslations` — Multi-language fields with fallback
- Depends on: Database
- Used by: Services, Controllers

**Frontend Layer:**
- Purpose: Interactive UI driven by server data.
- Location: `resources/js/Pages/` (page components), `resources/js/Layouts/` (wrappers)
- Layouts:
  - `AppLayout.vue` — Side menu, header, notifications; used by ERP pages.
  - `FinanceLayout.vue` — Tab bar for Finance sections (Invoices, Receipts, etc.); stacked on AppLayout.
  - `SiteLayout.vue` — Header, footer, theme switcher; public storefront.
  - `AuthSplitLayout.vue` — Login/register page.
  - `GuestLayout.vue` — Error pages.
- Pages are organized by domain: `Deals/`, `Projects/`, `Finance/`, `Catalog/`, `Site/`, etc.
- Depends on: Inertia props from controllers, shared data (auth, notifications, i18n)
- Uses: Shared components (Buttons, Forms, Tables), composables (useForm, useI18n)

## Data Flow

### Primary Request Path: Manage a Deal

1. User visits `/deals` (authenticated)
   - Route: `web.php` → `DealController@index()`
   - Middleware chain: Auth, SetCurrentCompany, SetLocale, HandleInertiaRequests
   - CurrentCompany middleware defaults user to their first company

2. `DealController@index()` calls `DealController::filteredDeals()` (`app/Http/Controllers/DealController.php`)
   - Applies filters (stage, manager, date, branch tabs)
   - Scopes to `Deal::forCurrentCompany()` (WHERE company_id = X)
   - Counts and calculates for each tab (all counts without applying filter, to show global state)

3. Returns `Inertia::render('Deals/Index', ['deals' => [...], 'stages' => [...], ...])`
   - Props include deal data with nested stage, responsible user, company
   - Stages are specific to this company's pipeline

4. Vue page `resources/js/Pages/Deals/Index.vue` renders kanban/list
   - Two view modes (toggleable)
   - Each deal card shows: name, stage, manager, budget, status

5. User clicks "Move Stage" or "Advanced"
   - POST to `/deals/{deal}/advance` → `DealController@advance()`
   - Calls `StageTransitionService::advance($deal)` (`app/Services/StageTransitionService.php`)
     - Guard: check user role and stage type (contract, design, shop_gate, etc.)
     - Gate tasks: if stage has gate_task_id, create Task for responsible user role
     - Transition logic: open new stage timer, close old timer (DealStageLog)
     - Run listeners: e.g., send to workshop if stage is `deal_to_project`
   - Broadcast notification to team
   - Return updated deal in Inertia response

6. Deal moves through stages: `Заявка` → `Договор` → `Замер` → (gated) → `Закуп` → (gated) → `В цех` (→ Project created) → …

### Workshop (Project) Request Path

1. Manager sends deal to workshop → `DealController@sendToWorkshop()` calls `ProjectService::create()`
   - Creates Project record with workshop city, first stage (Формовка for selected city)
   - Employees of that workshop see the order in their workshop kanban

2. Workshop screen (`/screen`) — no login, enter 6-digit code
   - `WorkshopScreenController@show()` returns screen UI for that workshop
   - "Далее" button: `screen/projects/{project}/advance` → auto-advances stage per workshop
   - "Готово" button (on last stage): `ProjectService::completeAndReturnDeal()` moves deal back to Logistics in main pipeline

3. Deal continues: Логистика → Монтаж → Акт → ЭСФ → Оплата успешно (is_won=true)

### Money Flow

1. **Invoice creation:** `InvoiceController@store()` → `Invoice` model (morphMany to Deal/Project)
   - User enters amount, date; system creates record
   - Status: pending until payment is made

2. **Payment:** `PaymentController@store()` → `Payment` model linked to Invoice
   - Moves money from invoice to "received"
   - Counts toward deal total received for bonus calculation

3. **Expense (materials/delivery):** `ExpenseController@store()` 
   - Type: material (from warehouse), delivery, category expense
   - Status: pending → confirmed (when financist checks receipt + payment method)
   - Expense polymorphically attached to Deal or Project
   - If confirmed: decreases cash/bank immediately

4. **Warehouse receipt:** `WarehouseController@receipt()` 
   - Material arrives, cost recorded
   - Auto-creates confirmed Expense for "Material Purchase"

5. **Payroll:** `PayrollService::dealBonus()` runs per deal
   - Input: deal budget, confirmed expenses, payments received, manager custom rate
   - Logic:
     - Tax = budget × tax% (Setting)
     - Remaining = budget − tax − expenses
     - Margin % = (budget − expenses) / budget
     - Bonus rate = stepped function of margin % (or override)
     - Manager bonus = rate × remaining × min(1, received/budget)
     - Plus: % of markup on warehouse items if used
   - Called by: Deal card, Payroll sheet, Analytics, Finance summary

**State Management:**
- Company and user are in session (not Vuex/Pinia)
- Individual page state managed by Vue components
- Real-time updates via WebSocket (chat) and polling (notifications, workshop screens)
- Cache invalidation: Stage change → broadcast; Settings change → route:cache-only (static files)

## Key Abstractions

**Polymorphic Finances:**
- Purpose: Expenses and invoices attach to either Deal or Project without duplicating logic.
- Implementation: `Expense::morphTo()` → resolves to Deal or Project via `expenseable_type`/`expenseable_id`
- Examples:
  - Delivery expense for Deal workflow
  - Material cost for Project (workshop order)
  - Both use same confirmation, payment logic
- Files:
  - `app/Models/Expense.php`, `app/Models/Invoice.php` (morphTo)
  - `app/Models/Deal.php`, `app/Models/Project.php` (morphMany)

**Company Isolation:**
- Purpose: Multi-company support (currently one: QAZAQ TAS); future-proofs for expansion.
- Implementation:
  - `SetCurrentCompany` middleware sets `session.company_id` to user's selected/first company
  - `CurrentCompany::id()` returns session value
  - Models scope queries: `Deal::forCurrentCompany()` WHERE company_id = current
  - Financist/admin with 2+ companies can select "All" (company_id = 0)
- Files: `app/Support/CurrentCompany.php`, `app/Http/Middleware/SetCurrentCompany.php`

**Multi-language with Fallback:**
- Purpose: Kazakh main, Russian secondary; UI + content translated.
- Implementation:
  - `SetLocale` resolves language: user profile → session → setting
  - `app/Support/Locales::default()` and `::ALL` define available locales
  - Content translations in `*_translations` tables (Products, Categories, SiteProjects)
  - UI translations in `UiTranslation` (filenames: `lang/{kk,ru}/site.php` for site, `lang/kk/erp.php` for ERP)
  - `localized()` method on models returns translated or base value
  - Inertia shares current locale + all i18n meta with every page
- Files:
  - `app/Support/Locales.php` (language registry)
  - `app/Http/Middleware/SetLocale.php`
  - `app/Models/UiTranslation.php`
  - `resources/js/i18n.js` (frontend)

**Deal/Project Stage Lifecycle with Timestamps:**
- Purpose: Track how long an order spends on each stage for management analytics.
- Implementation:
  - `DealStageLog` / `ProjectStageLog` — Open/close records per stage
  - On stage change: close old timer, open new
  - On deal cancel: close all timers
  - Timestamps: `entered_at`, `left_at`, duration = left_at − entered_at
  - Used by: Kanban card badges ("⏱ 2ч 30м"), Analytics (bottleneck detection)
- Files: `app/Models/DealStageLog.php`, `app/Models/ProjectStageLog.php`

**Gate Tasks (Stage-Blocking Approval):**
- Purpose: Halt deal progress until person in specific role approves/completes work.
- Implementation:
  - Stage config: `gate_task_role` (e.g., "designer"), `gate_task_text`, `gate_task_days`
  - On entry: auto-create Task for all active users of that role
  - Deal cannot advance until task(s) closed
  - Completion: `DealController@completeStageTask()` marks task done
- Files: `app/Models/Task.php`, `app/Services/StageTransitionService.php`

## Entry Points

**Dashboard:**
- Location: `GET /dashboard`
- Controller: `DashboardController@index()` (`app/Http/Controllers/DashboardController.php`)
- Returns: Quick summary of user's deals/tasks/finance
- Access: Requires auth

**ERP Pages:**
- All under `/login` and authenticated routes
- Main sections: Deals, Projects, PreDeals, Finance (groups: Invoices, Receipts, Debts, Expenses, Payroll), Catalog, Users, Settings, Audit
- Navigation: Sidebar menu (AppLayout); Finance uses stacked tabs (FinanceLayout)

**Workshop Screen:**
- Location: `GET /screen`
- Controller: `WorkshopScreenController@show()` (`app/Http/Controllers/WorkshopScreenController.php`)
- Access: Public (code-gated, 6-digit code required)
- Purpose: TV display for production floor; advance orders, mark complete

**Public Storefront:**
- Location: `/` (home), `/katalog` (catalog), `/korzina` (cart), `/oformlenie` (checkout), `/proekty` (projects), `/kontakty` (contacts)
- Controller: `Site/PageController`, `Site/CatalogController`, etc. (`app/Http/Controllers/Site/`)
- Access: Public (no login)
- Layouts: SiteLayout (header, footer, theme switcher)

## Architectural Constraints

- **Threading:** Single-threaded event loop (Laravel standard); no background workers except scheduler (cronjobs for notifications, debt charging).
- **Global state:** 
  - Session: `company_id`, `user_id` (Laravel built-in), workshop screen code
  - No module-level singletons except `CurrentCompany::id()` helper
- **Circular imports:** None known; strict PSR-4 autoloading.
- **Deployment:** `vendor/` and `public/build/` NOT in git; CI builds frontend, composer installs on deploy.
- **Database transactions:** Used in critical paths (payment processing, expense confirmation) to ensure consistency.
- **File storage:** Photos/models in `storage/app/public/` (symlinked to `public/storage/`); no git.
- **Cache:** File-based in dev/staging; no cache backend requirement for prod (can add Redis if scaling).

## Anti-Patterns

### Querying Everything Then Filtering in PHP

**What happens:** Controller loads all deals, then filters in PHP instead of database WHERE clause.
**Why it's wrong:** N+1 problem; slow with large datasets; violates scoping (company filters, role-based filters should be SQL).
**Do this instead:** Apply scopes at query time:
```php
Deal::forCurrentCompany()
    ->where('deal_stage_id', $stageId)
    ->whereHas('responsible', fn ($q) => $q->where('user_id', auth()->id()))
    ->get()
```
See: `DealController::filteredDeals()` (`app/Http/Controllers/DealController.php:~line 480`)

### Duplicate Finance Calculations

**What happens:** Bonus calculated in PayrollService, then re-calculated in Analytics, causing drift.
**Why it's wrong:** Money is critical; drift = audit failures.
**Do this instead:** Single source of truth: `PayrollService::dealBonus()` (`app/Services/PayrollService.php`).
Called by: Payroll sheet, Analytics, Finance dashboard. Results are deterministic.

### Missing Company Scope in Queries

**What happens:** Query returns another company's data when user switches companies.
**Why it's wrong:** Data leak; confidentiality breach.
**Do this instead:** All deal/project/expense queries use `forCurrentCompany()` scope or explicit WHERE.
Middleware ensures CurrentCompany is set; if unset, no results returned.
See: `DealController@filteredDeals()`, all Finance controllers

## Error Handling

**Strategy:** Exception-driven with fallback to 404.

**Patterns:**
- Model not found → 404 (Laravel automatic)
- Authorization fails → 403 (Policy returns false)
- Validation fails → 422 (FormRequest redirect back with errors)
- Duplicate operation (e.g., double-click "confirm") → Idempotency key check or unique constraint → silent no-op
- Finance invariant broken (overpayment, negative balance) → Transaction rollback + 422 with message

**Notification on Critical Deletions:**
- Delete deal → notify admin/director
- Delete expense → notify owner (deal/project card)
- Delete invoice → notify owner
- File: `app/Support/NotificationResolver.php` maps deletion event to owner

## Cross-Cutting Concerns

**Logging:**
- Approach: Laravel built-in; write to `storage/logs/laravel.log`
- Critical paths (finance, stage transitions) log at INFO level
- Audit trail: `AuditLog` model tracks all model changes (who, what, when)
- File: `app/Models/Concerns/Auditable.php`, `app/Models/AuditLog.php`

**Validation:**
- Approach: `FormRequest` classes in `app/Http/Requests/`
- Rules: Built-in + custom rules for complex logic (e.g., margin threshold)
- Multilingual error messages in `lang/{kk,ru}/validation.php`

**Authentication & Authorization:**
- Auth: Laravel Breeze (session-based)
- Permissions: spatie/laravel-permission (roles + per-resource policies)
- Admin is special: `Gate::before()` checks if admin, grants all
- Policies: `app/Policies/` — `DealPolicy`, `ProjectPolicy`, etc. check `can('update', $deal)`

**Notifications:**
- Channels: In-app (database), email (future), Web Push (for chat)
- File: `app/Notifications/` contains event-driven notifications (OrderCreated, StageAdvanced, etc.)
- Resolved by `NotificationResolver` — maps polymorphic event data to user + link

---

*Architecture analysis: 2026-08-17*
