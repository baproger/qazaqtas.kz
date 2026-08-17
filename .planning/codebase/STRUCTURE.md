# Codebase Structure

**Analysis Date:** 2026-08-17

## Directory Layout

```
project-root/
├── app/                              # PHP application code (PSR-4 namespaced)
│   ├── Console/                      # Artisan commands: notifications, debt charging, cronjobs
│   ├── Http/
│   │   ├── Controllers/              # Request handlers for ERP and site
│   │   │   ├── Site/                 # Public storefront controllers (no auth)
│   │   │   ├── DealController.php    # Deal CRUD, stage advance, send to workshop
│   │   │   ├── ProjectController.php # Workshop order views and stage management
│   │   │   ├── InvoiceController.php # Finance overviews, invoice/receipt/debt pages
│   │   │   ├── ExpenseController.php # Expense entry and confirmation
│   │   │   ├── PayrollController.php # Salary, bonuses, adjustments
│   │   │   └── [More controllers...]
│   │   ├── Middleware/               # Request preprocessing
│   │   │   ├── SetCurrentCompany.php # Isolate by company session
│   │   │   ├── SetLocale.php         # Resolve user language
│   │   │   ├── HandleInertiaRequests.php # Share auth, i18n, notifications
│   │   │   └── SecureHeaders.php
│   │   └── Requests/                 # FormRequest validation classes
│   ├── Models/                       # Eloquent ORM entities
│   │   ├── Deal.php                  # Sales deal with invoice/expense morphMany relations
│   │   ├── Project.php               # Workshop order (цех) with same morphMany relations
│   │   ├── Expense.php               # Polymorphic: belongs to Deal or Project
│   │   ├── Invoice.php               # Polymorphic: belongs to Deal or Project
│   │   ├── User.php                  # Employee with roles/permissions
│   │   ├── Company.php               # Legal entity (isolation scope)
│   │   ├── DealStage.php             # Pipeline stages for deals
│   │   ├── ProjectStage.php          # Pipeline stages for workshops
│   │   ├── Material.php              # Warehouse inventory
│   │   ├── Product.php               # Catalog items for storefront
│   │   ├── Task.php                  # Morphic tasks (for Deal/Project)
│   │   ├── UiTranslation.php         # i18n for UI strings
│   │   ├── Concerns/
│   │   │   ├── Auditable.php         # Track changes in AuditLog
│   │   │   └── HasTranslations.php   # Fallback to base language
│   │   └── [More models...]
│   ├── Services/                     # Business logic, reusable across controllers
│   │   ├── FinanceService.php        # Company balances, invoices, debtors
│   │   ├── PayrollService.php        # Salary, bonuses, monthly calculations
│   │   ├── ProjectService.php        # Workshop logic: complete and return to deal
│   │   ├── StageTransitionService.php# Guard transitions, run gate tasks, notify
│   │   ├── EmployeeDebtService.php   # Employee debt + auto-charging
│   │   ├── MediaService.php          # Image resize, WebP conversion, 3D storage
│   │   ├── CatalogService.php        # 3D scene assets, product helpers
│   │   └── [More services...]
│   ├── Policies/                     # Authorization rules per model
│   │   ├── DealPolicy.php            # Can view/update/delete this deal?
│   │   ├── ProjectPolicy.php         # Can view/update this project?
│   │   └── [More policies...]
│   ├── Notifications/                # Event-driven user notifications
│   │   ├── OrderCreatedNotification.php
│   │   └── [More notifications...]
│   ├── Support/                      # Helper classes
│   │   ├── CurrentCompany.php        # Get/set company from session
│   │   ├── Locales.php               # Language registry, locale helpers
│   │   ├── SiteContent.php           # Site metadata (contacts, tiers, FAQ, etc.)
│   │   ├── FinanceAudit.php          # Validate money invariants
│   │   └── NotificationResolver.php  # Map polymorphic event to user + link
│   └── Providers/                    # Service providers (boot)
│
├── routes/                           # Route definitions
│   ├── web.php                       # ERP endpoints (auth required)
│   ├── site.php                      # Public storefront (no auth)
│   ├── auth.php                      # Login/register (handled by Breeze)
│   └── console.php                   # Scheduled commands
│
├── database/
│   ├── migrations/                   # Schema changes
│   ├── seeders/                      # Initial data (users, settings, stages)
│   └── factories/                    # Test data generators
│
├── resources/
│   ├── js/
│   │   ├── Pages/                    # Vue components for Inertia pages
│   │   │   ├── Deals/
│   │   │   │   ├── Index.vue         # Kanban/list view
│   │   │   │   ├── Show.vue          # Deal card with tabs (Finance, Tasks, Documents, Chat)
│   │   │   │   └── ...
│   │   │   ├── Projects/             # Workshop orders
│   │   │   ├── Finance/              # Finance sections
│   │   │   │   ├── Invoices.vue      # Invoices page
│   │   │   │   ├── Receipts.vue      # Poступления page
│   │   │   │   ├── Debts.vue         # Дебиторка page
│   │   │   │   └── ...
│   │   │   ├── Payroll/              # Salary page
│   │   │   ├── Catalog/              # Product catalog management
│   │   │   ├── Site/                 # Public storefront pages
│   │   │   │   ├── Home.vue          # Main page with 3D scene
│   │   │   │   ├── Catalog.vue       # Product listing
│   │   │   │   ├── Product.vue       # Product detail
│   │   │   │   ├── Cart.vue          # Shopping cart
│   │   │   │   └── Checkout.vue      # Order form
│   │   │   ├── PreDeals/
│   │   │   ├── Users/
│   │   │   ├── Settings/
│   │   │   ├── Chat/
│   │   │   ├── Auth/                 # Login, register
│   │   │   └── ...
│   │   ├── Layouts/
│   │   │   ├── AppLayout.vue         # ERP wrapper: sidebar menu, header, notifications
│   │   │   ├── FinanceLayout.vue     # Stacked with AppLayout; adds Finance tab bar
│   │   │   ├── SiteLayout.vue        # Storefront: no menu, just header/footer
│   │   │   └── ...
│   │   ├── Components/               # Reusable Vue components
│   │   │   ├── Buttons.vue
│   │   │   ├── Forms/
│   │   │   ├── Tables/
│   │   │   ├── Cards/
│   │   │   └── ...
│   │   ├── composables/              # Vue 3 composition functions
│   │   │   ├── useForm.js
│   │   │   ├── useI18n.js
│   │   │   └── ...
│   │   ├── utils/                    # Helpers (formatting, date, etc.)
│   │   ├── site/                     # Site-specific logic
│   │   │   ├── gltf.js               # 3D model loader (GLB/OBJ)
│   │   │   └── localeScroll.js       # Preserve scroll position on language switch
│   │   └── i18n.js                   # Frontend translation functions
│   ├── css/
│   │   ├── app.css                   # Import all below + Tailwind
│   │   ├── tokens.css                # Color variables (day/night themes)
│   │   ├── surfaces.css              # Card, buttons, glass effects
│   │   ├── site.css                  # Storefront layout
│   │   └── hero.css                  # Storefront hero section
│   ├── views/
│   │   ├── app.blade.php             # Root Inertia template
│   │   ├── pdf/                      # Quotation PDF template
│   │   └── errors/                   # Error pages (4xx, 5xx)
│   └── lang/
│       ├── kk/                       # Kazakh translations
│       │   ├── erp.php               # ERP UI (Russian strings → Kazakh)
│       │   ├── site.php              # Storefront content
│       │   └── app.php               # Shared (pagination, etc.)
│       └── ru/                       # Russian translations (if main is Kazakh)
│           ├── site.php
│           └── app.php
│
├── tests/
│   ├── Unit/                         # Unit tests (isolated logic)
│   ├── Feature/                      # Feature tests (full requests)
│   └── CreatesApplication.php        # Test helpers
│
├── config/                           # Laravel config
│   ├── qazaqtas.php                  # Custom app settings (roles, stages, etc.)
│   ├── database.php
│   ├── mail.php
│   └── ...
│
├── storage/
│   ├── app/
│   │   └── public/                   # User uploads (photos, 3D models, documents)
│   │       ├── catalog/{id}/         # Product images and files
│   │       ├── categories/{id}/      # Category images
│   │       ├── avatars/              # User profile pictures
│   │       └── ...
│   ├── logs/                         # Application logs
│   └── framework/
│
├── public/
│   ├── build/                        # Compiled frontend (NOT in git; from CI or npm run build)
│   ├── storage/                      # Symlink to storage/app/public
│   ├── logo-qazaqtas.svg
│   ├── index.php                     # Laravel entry point
│   └── .htaccess
│
├── bootstrap/
│   ├── app.php                       # Application bootstrapper
│   └── cache/                        # Cached routes, config (generated by: artisan optimize)
│
├── .claude/                          # Claude Code project config
│   ├── settings.json
│   ├── keybindings.json
│   └── gsd-core/                     # GSD framework
│
├── .github/
│   ├── workflows/
│   │   └── ci.yml                    # CI pipeline: test, build, upload artifact
│   └── ...
│
├── .planning/
│   └── codebase/                     # This directory; codebase maps
│
├── .env                              # LOCAL ONLY: secrets, db, key (in .gitignore)
├── .env.example                      # Template for .env
├── composer.json                     # PHP dependencies
├── composer.lock                     # Locked versions
├── package.json                      # Node dependencies (Vue, Tailwind, etc.)
├── package-lock.json
├── vite.config.js                    # Frontend build config
├── tailwind.config.js                # Tailwind CSS config
├── phpunit.xml                       # Test config
├── PROJECT.md                        # Project summary (owner's words)
├── DEPLOY.md                         # Deployment procedure
└── README.md
```

## Directory Purposes

**app/Http/Controllers/**
- Purpose: Handle HTTP requests; parse input, call services, return responses
- Contains: One controller per major feature (Deals, Projects, Finance, etc.)
- Pattern: Public methods return `Inertia::render()` or `response()->json()` or redirect
- Example: `DealController@sendToWorkshop()` calls `ProjectService::create()` then returns redirect/Inertia

**app/Services/**
- Purpose: Encapsulate business logic; reusable across controllers
- Contains: Pure logic (no HTTP concerns), stateless
- Pattern: Public methods accept domain objects (Model, Request), return results
- Example: `PayrollService::dealBonus()` takes Deal and User, returns calculated bonus

**app/Models/**
- Purpose: Define schema, relationships, and model-level logic
- Contains: Eloquent models with relations, scopes, accessors, mutators
- Pattern: Relationships defined as methods; no HTTP/request logic in models
- Key trait: `Auditable` — auto-records changes in `audit_logs`

**app/Policies/**
- Purpose: Authorization rules per model
- Contains: `authorize($user, $model)` → true/false
- Pattern: Called by controller via `$this->authorize('update', $deal)`; also works in middleware
- Example: `DealPolicy@update()` checks if user is admin or deal's responsible manager

**resources/js/Pages/**
- Purpose: Vue components rendered by Inertia for each route
- Contains: One component per page (Deals/Index.vue, Deals/Show.vue, etc.)
- Pattern: Receive props from controller; define local state; emit actions to server via HTTP
- Example: `Finance/Invoices.vue` receives `invoices` prop, user can add/delete invoices

**resources/js/Layouts/**
- Purpose: Wrapper components for pages; shared chrome (menu, header, footer)
- Contains: 
  - `AppLayout.vue` — Side menu with sections, logout, company switcher
  - `FinanceLayout.vue` — Tab bar for Finance subsections; stacks with AppLayout
  - `SiteLayout.vue` — Public pages; no menu, just header/footer
- Pattern: Use as parent in page component via `layout: FinanceLayout`

**resources/js/Components/**
- Purpose: Reusable UI elements (buttons, forms, modals, tables)
- Contains: Small, focused components (Button.vue, Modal.vue, DataTable.vue)
- Pattern: Accept props for data/config, emit events for actions
- No HTTP logic; pure rendering

**database/migrations/**
- Purpose: Define schema changes
- Contains: One file per change; reversible (up/down)
- Pattern: Run via `php artisan migrate`, reverted via `php artisan migrate:rollback`
- Example: `2024_08_16_create_employees_debt_table.php`

**database/seeders/**
- Purpose: Populate initial data
- Contains: Classes that insert seed data (users, settings, materials, stages)
- Pattern: Run via `php artisan db:seed`, scoped (--class=SiteSettingsSeeder)
- Example: `StageSeeder` creates pipeline stages for each company/workshop

**resources/lang/{locale}/**
- Purpose: Translation strings
- Contains: 
  - `erp.php` — UI strings (Russian key → Kazakh value)
  - `site.php` — Storefront content (Russian key → Kazakh value)
  - `validation.php` — Error messages
- Pattern: Loaded by `app()->getLocale()`; fallback to key if missing
- Example: `$e('Сохранить')` returns Russian if lang is Russian, Kazakh otherwise

**storage/app/public/**
- Purpose: Persistent user uploads (photos, models, documents)
- Contains:
  - `catalog/{product_id}/` — Product images, 3D models
  - `categories/{category_id}/` — Category images
  - `avatars/{user_id}.jpg` — User profiles
- Pattern: Served via symlink `public/storage/` (created by `php artisan storage:link`)
- NOT in git; backed up separately

**public/build/**
- Purpose: Compiled frontend (CSS, JS bundles)
- Contains: Generated by `npm run build` (Vite)
- NOT in git; recreated on deploy via CI or `npm ci && npm run build`
- Pattern: Cache-busted filenames (hash in name); can set `Cache-Control: immutable, 1y`

**config/**
- Purpose: Application configuration
- Contains: 
  - `qazaqtas.php` — Custom app settings (minimum margin, bonus tiers, workshop list)
  - `database.php`, `mail.php`, `services.php` (standard Laravel)
- Pattern: Accessed via `config('qazaqtas.setting_key')`; can be cached via `php artisan config:cache`

## Key File Locations

**Entry Points:**
- `public/index.php` — HTTP entry point (calls bootstrap/app.php)
- `routes/web.php` — ERP routes (auth required)
- `routes/site.php` — Public storefront (no auth)
- `app/Http/Controllers/DashboardController.php` — First page user sees after login

**Configuration:**
- `app/Support/CurrentCompany.php` — Company session logic
- `app/Support/Locales.php` — Language registry and helpers
- `app/Http/Middleware/SetCurrentCompany.php` — Set company per request
- `app/Http/Middleware/SetLocale.php` — Resolve user language
- `config/qazaqtas.php` — Custom app settings

**Core Logic:**
- `app/Services/FinanceService.php` — Company finances (balance, invoices, debtors)
- `app/Services/PayrollService.php` — Salary and bonus calculations
- `app/Services/StageTransitionService.php` — Deal/Project stage validation and transitions
- `app/Services/ProjectService.php` — Workshop (цех) logic
- `app/Services/EmployeeDebtService.php` — Employee debt + auto-charging

**Data Models:**
- `app/Models/Deal.php` — Sales deal (morph parent for expenses/invoices)
- `app/Models/Project.php` — Workshop order (morph parent for expenses/invoices)
- `app/Models/Expense.php` — Cost entry (morphs to Deal or Project)
- `app/Models/Invoice.php` — Billing entry (morphs to Deal or Project)
- `app/Models/User.php` — Employee with roles/permissions
- `app/Models/Company.php` — Legal entity (scoping)

**Frontend:**
- `resources/js/Layouts/AppLayout.vue` — Main ERP wrapper (sidebar menu)
- `resources/js/Layouts/FinanceLayout.vue` — Finance section wrapper (tabs)
- `resources/js/Layouts/SiteLayout.vue` — Storefront wrapper
- `resources/js/Pages/Deals/Index.vue` — Deal kanban/list
- `resources/js/Pages/Finance/Invoices.vue` — Invoices page
- `resources/js/site/gltf.js` — 3D model loader

**Testing:**
- `tests/Feature/` — Full request tests
- `tests/Unit/` — Isolated logic tests
- `phpunit.xml` — Test config

## Naming Conventions

**Files:**
- Controllers: `{Domain}Controller.php` (e.g., `DealController.php`, `PayrollController.php`)
- Services: `{Domain}Service.php` (e.g., `FinanceService.php`, `ProjectService.php`)
- Models: `{Entity}.php` (e.g., `Deal.php`, `Expense.php`)
- Migrations: `YYYY_MM_DD_HHmmss_action_on_table.php` (e.g., `2024_08_16_create_expenses_table.php`)
- Vue components (Pages): `{Page}.vue` (PascalCase, e.g., `DealsIndex.vue` for routes)
- Vue components (Reusable): `{Component}.vue` (PascalCase, e.g., `Button.vue`, `Modal.vue`)

**Directories:**
- Controllers: Grouped by domain if large (e.g., `Site/` for storefront, `Finance/` for financial sections)
- Models: Flat (all in `app/Models/`); use Concerns for shared logic (`app/Models/Concerns/`)
- Services: Flat (all in `app/Services/`)
- Tests: Mirror app structure (`tests/Unit/Services/`, `tests/Feature/Controllers/`)

**Database:**
- Tables: Snake case, plural (e.g., `deals`, `expenses`, `users`)
- Columns: Snake case (e.g., `deal_stage_id`, `responsible_user_id`)
- Relationships: Foreign keys named `{model_id}` (e.g., `user_id`, `company_id`)
- Timestamps: `created_at`, `updated_at` (Laravel standard); soft deletes add `deleted_at`
- Booleans: Prefix `is_` (e.g., `is_active`, `is_won`)

**Vue/JavaScript:**
- Variables: camelCase (e.g., `dealId`, `isLoading`, `openModal`)
- Functions: camelCase (e.g., `submitForm()`, `onStageChange()`)
- Components: PascalCase (e.g., `DealCard.vue`, `ExpenseForm.vue`)
- CSS classes: Kebab case (e.g., `.deal-card`, `.finance-layout`)

## Where to Add New Code

**New Feature (e.g., new Deal workflow stage):**
1. **Database:**
   - Create migration: `database/migrations/YYYY_MM_DD_hhmmss_add_new_stage_to_deals.php`
   - Add column/table if needed

2. **Model:**
   - Update model if new relationship: `app/Models/Deal.php` or create new model in `app/Models/`
   - Add scope if filtering needed: `public function scopeNewStage($query) { ... }`

3. **Service:**
   - Create `app/Services/{Feature}Service.php` or add method to existing service
   - Example: `StageTransitionService::handleNewStage()` or new `DealAdvancementService`

4. **Controller:**
   - Create route in `routes/web.php` or add method to existing controller
   - Example: `Route::post('deals/{deal}/new-stage', [DealController::class, 'newStage'])`
   - Add method: `public function newStage(Deal $deal) { ... }`

5. **Policy:**
   - Update `app/Policies/DealPolicy.php` or `app/Policies/{Feature}Policy.php`
   - Add authorization check: `public function newStage(User $user, Deal $deal) { ... }`

6. **Frontend:**
   - Update Vue page: `resources/js/Pages/Deals/Show.vue` or `Deals/Index.vue`
   - Add form/button to trigger server action

7. **Tests:**
   - Add feature test: `tests/Feature/DealAdvancementTest.php`
   - Add unit test: `tests/Unit/Services/StageTransitionServiceTest.php`

**New API Endpoint (e.g., export deals):**
1. Create route in `routes/web.php`: `Route::get('deals/export', [DealController::class, 'export'])`
2. Add controller method: `public function export() { return Excel::download(...); }`
3. Call service if logic is complex: `$data = $this->service->prepareExportData()`
4. Test: `tests/Feature/DealExportTest.php`

**New Component (reusable UI):**
1. Create: `resources/js/Components/{Category}/{Component}.vue`
2. Import in page: `import Button from '@/Components/Button.vue'`
3. Use with props: `<Button label="Save" @click="submit" />`

**New Service Helper:**
1. Create: `app/Support/{Helper}.php`
2. Example: `app/Support/PdfGenerator.php` for quotation PDFs
3. Register as singleton in `app/Providers/AppServiceProvider.php` if needed

**New Notification:**
1. Create: `app/Notifications/{Event}Notification.php`
2. Define channels (database, email, push)
3. Dispatch: `$user->notify(new {Event}Notification($data))`

## Special Directories

**storage/app/public/**
- Purpose: Persistent user uploads
- Generated: Auto-created by Laravel
- Committed: NO (in `.gitignore`)
- Backed up: Separately via `scripts/backup.sh`
- Served: Via `public/storage/` symlink (created by `php artisan storage:link`)

**public/build/**
- Purpose: Compiled frontend assets
- Generated: By `npm run build` (Vite)
- Committed: NO (in `.gitignore`; recreated on deploy)
- Cache: Static files use `Cache-Control: immutable, 1y` (filenames are hashed)

**bootstrap/cache/**
- Purpose: Cached config, routes, views (performance)
- Generated: By `php artisan optimize` (run on deploy)
- Committed: NO (in `.gitignore`)
- Cleared: By `php artisan optimize:clear` before development

**database/migrations/**
- Purpose: Version control for schema
- Committed: YES (git tracks all)
- Naming: `YYYY_MM_DD_HHmmss_description.php` (timestamp prevents conflicts)
- Running: `php artisan migrate` (applies all pending), `php artisan migrate:rollback` (undo last batch)

**lang/{locale}/**
- Purpose: Translations for UI + content
- Committed: YES (part of code)
- Structure: One file per domain (`erp.php` for ERP UI, `site.php` for storefront)
- Editing: Either directly in files or via Settings → Translations in ERP UI (`UiTranslation` table)

**.env**
- Purpose: Local secrets (db password, app key, external service keys)
- Committed: NO (in `.gitignore`; `.env.example` is in git)
- Created: Copy `.env.example` to `.env` and fill in values
- Production: Set via server environment or .env uploaded by deploy script

**tests/**
- Purpose: Automated testing
- Committed: YES (all tests in git)
- Structure: Mirror app structure (tests/Feature/Controllers/, tests/Unit/Services/)
- Running: `php artisan test` or `./vendor/bin/phpunit`
- Coverage: `php artisan test --coverage` (target 80%+)

---

*Structure analysis: 2026-08-17*
