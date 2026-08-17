# Technology Stack

**Analysis Date:** 2026-08-17

## Languages

**Primary:**
- PHP 8.3.30 - Backend runtime; locked in `composer.json` `config.platform.php=8.3.30`; production server runs Plesk alt-php 8.3.30

**Secondary:**
- JavaScript/TypeScript (ES2020+) - Frontend logic via Vue 3 and Node build tools; production uses bundled/minified output

## Runtime & Package Managers

**Environment:**
- Laravel 13 (^13.8) - Full-stack framework
- Node v22 - Used for build/dev only (npm); **not shipped to production**

**Package Managers:**
- Composer - PHP dependency manager; `composer.phar` stored in repo for Plesk deployment
- npm - JavaScript dependency manager; `package.json` defines dev and runtime dependencies
- Lockfiles: `composer.lock`, `package-lock.json` both present and committed

## Frameworks & Core Libraries

**Backend:**
- Laravel 13 - Application framework with Eloquent ORM, migrations, artisan CLI
- Inertia.js 2.0 - Server-side rendering bridge between Laravel + Vue 3; defined in `inertiajs/inertia-laravel: ^2.0`

**Frontend:**
- Vue 3 (^3.4.0) - Component-based UI framework for ERP and storefront
- Tailwind CSS (^3.2.1) - Utility-first CSS framework with forms plugin (`@tailwindcss/forms: ^0.5.3`)
- Vite (^8.0.0) - Frontend build tool with `laravel-vite-plugin: ^3.1`
- PostCSS (^8.4.31) - CSS transformation via `postcss-import: ^16.2.0` to process `@import` statements before Tailwind

**3D & Animation (Storefront Only):**
- Three.js (^0.185.1) - 3D scene rendering for product visualization and configurable yard assembly
- GSAP (^3.15.0) - ScrollTrigger plugin for scroll-driven animations on hero section
- Lenis (^1.3.25) - Smooth scrolling library; imported as separate chunk only when needed
- All three ship as production dependencies and are lazy-loaded per page

**Testing:**
- PHPUnit (^12.5.12) - Backend testing framework; 508 tests in suite
- Mockery (^1.6) - Mocking library for unit tests

**Code Quality & Development:**
- Laravel Pint (^1.27) - Code formatting/style checker
- Laravel Pail (^1.2.5) - Log viewer for real-time tail
- Faker (^1.23) - Fake data generation for seeders
- Collision (^8.6) - Pretty error rendering in CLI

## Key Dependencies

**Critical:**
- `laravel/sanctum: ^4.0` - API token authentication (used for web-push and potential mobile/3rd-party auth)
- `spatie/laravel-permission: ^8.1` - RBAC implementation; roles & permissions stored in tables managed by this package
  - Configuration: `config/permission.php`
  - Tables: `roles`, `permissions`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`
- `barryvdh/laravel-dompdf: ^3.1` - PDF generation for quotation prints (КП)
- `minishlink/web-push: ^9.0` - Web Push API client; sends notifications via browser service worker
- `tightenco/ziggy: ^2.0` - Route generation for JavaScript; exposes Laravel routes to Vue components

**Build & Frontend:**
- `laravel-vite-plugin: ^3.1` - Laravel integration for Vite; compiles assets and generates manifest
- `@vitejs/plugin-vue: ^6.0.0` - Vue 3 support in Vite
- `@inertiajs/vue3: ^2.0.0` - Vue 3 adapter for Inertia
- `autoprefixer: ^10.4.12` - CSS vendor prefixing via PostCSS
- `concurrently: ^9.0.1` - Run multiple commands in parallel during development

**Utilities:**
- `axios: ^1.18.1` - HTTP client for API calls (used within Vue components)
- `laravel/tinker: ^3.0` - REPL for interactive PHP shell

## Configuration

**Environment Variables:**
- `.env.example` provided; key variables:
  - `APP_ENV` (production/local), `APP_DEBUG` (false in production)
  - `APP_TIMEZONE=Asia/Almaty` - Fixed timezone for all calculations
  - `APP_LOCALE=kk`, `APP_FALLBACK_LOCALE=ru` - Kazakh primary, Russian fallback
  - `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT=3306`, database, credentials
  - `ADMIN_PASSWORD` - Generated on first seed if empty; **must be set before initial migration**
  - `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE` - Overridden per environment in code
  - `MAIL_MAILER=log` (default), supports SMTP, Postmark, Resend, SES
  - `AWS_*` - S3 bucket credentials (optional)
  - `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` - Web Push VAPID keys (optional)

**Build Configuration:**
- `vite.config.js` - Managed by `laravel-vite-plugin`; compiles entry point `resources/js/app.js`
- `.env.example` includes `VITE_APP_NAME`
- Production: `npm run build` outputs to `public/build/`; hashed filenames for cache-busting
- Collected as GitHub Actions artifact (`build`) to avoid Node.js on production server

**Database:**
- Driver: MySQL 5.7+ (in `.env` as `DB_CONNECTION=mysql`)
- Migrations: `database/migrations/`
- Seeders: Role/permission seeding, company config, stage setup, materials, site content
- Key tables (per PROJECT.md): `users`, `roles`, `permissions`, `deals`, `projects`, `expenses`, `products`, `orders`, `sessions`, `cache` (if using database driver), `jobs` (if using database queue)

**Queue, Cache & Sessions (Environment-Aware):**
- **Production** (detected in `config/queue.php`, `config/cache.php`, `config/session.php`):
  - Queue: `sync` (inline execution; no background processing)
  - Cache: `file` (`storage/framework/cache/data`)
  - Sessions: `file` (`storage/framework/sessions`)
  - Rationale: Plesk hosting lacks queue worker; file drivers avoid database load on shared hosting
- **Development/Testing** (respects `.env` settings):
  - Queue: `database` (stored in `jobs` table, needs queue listener)
  - Cache: `database` (stored in `cache` table)
  - Sessions: `database` (stored in `sessions` table)

**Scheduled Commands:**
- Defined in `routes/console.php`:
  - `tasks:notify-overdue` - Hourly
  - `users:notify-birthdays` - 09:00 daily
  - `pre-deals:notify-quote-deadline` - 09:00 daily (quotation expiry reminders)
  - `debts:charge` - 09:00 on 1st of month (employee debt auto-deduction from bonus)
  - `expenses:notify-stale` - 09:30 daily (stale expense reminder)
- Requires `php artisan schedule:work` to run locally or cron job on server

## Filesystem Disks

**Default Disk:** `local` (private storage)

**Configured Disks:**
- `local` - Root: `storage/app/private`; private files, inaccessible via web
- `public` - Root: `storage/app/public`; URL: `{APP_URL}/storage`; used for:
  - Product photos (original + `600px` preview via `MediaService`)
  - Category images (`1600×1600` PNG/WebP with transparency)
  - 3D models (GLB/GLTF/OBJ)
  - Generated WebP copies (lossless, half the size of originals)
- `s3` (optional) - AWS S3 bucket; credentials in `.env` as `AWS_*`; not currently used

**Media Handling:**
- `app/Services/MediaService` - Handles upload, resize, and WebP conversion
- Photos resized to 1600px + 600px preview
- PNG/WebP preserves transparency; JPEG used for photos
- Command `php artisan media:webp` generates WebP copies for older uploads
- Symbolic link required: `php artisan storage:link` (creates `public/storage` → `storage/app/public`)

## Platform Requirements

**Development:**
- PHP 8.3+ with extensions: `mbstring`, `bcmath`, `intl`, `zip`, `gd` (image manipulation), `sqlite3` or `pdo_sqlite` (test database)
- Node.js v22 (npm v10+)
- MySQL 5.7+ (or compatible)
- Git for version control

**Production (Plesk):**
- PHP 8.3.30 (alt-php in Plesk)
- MySQL database server
- `php composer.phar` available (stored in repo)
- Sufficient `storage/` and `public/storage` permissions for file uploads (775 recommended)
- Nginx or Apache with `public/` as document root
- OPcache enabled (highly recommended for performance)
- 2 GB+ free disk for backups (14-day rolling window of backups stored)

**Deployment Pipeline:**
- GitHub repository with automatic Plesk Git webhook integration
- `composer install --no-dev` runs automatically on each push
- `php artisan migrate --force` and `php artisan optimize` on each push
- Frontend built via GitHub Actions (artifact uploaded and used on production, or `npm ci && npm run build` on server)

## Build & Compilation

**Frontend Build:**
```bash
npm run build       # Production build (Vite); outputs hashed assets to public/build/
npm run dev         # Development mode (watch & HMR on http://localhost:5173)
```

**Backend Optimization (Production):**
```bash
php artisan config:cache      # Cache config files
php artisan route:cache       # Cache route definitions
php artisan view:cache        # Pre-compile Blade/Inertia views
php artisan optimize          # Combines above + OPcache optimization
```

**Browser Compatibility:**
- Chrome ≥ 110
- Edge ≥ 110
- Firefox ≥ 110
- Safari ≥ 16

## CI/CD Pipeline

**GitHub Actions Workflow** (`.github/workflows/ci.yml`):
- Triggered on: push to `master`/`main`, all pull requests
- PHP 8.3 with extensions: gd, sqlite3, pdo_sqlite, mbstring, bcmath, intl, zip
- Steps:
  1. Install PHP dependencies (`composer install`)
  2. Audit dependencies (`composer audit` - fails on vulnerabilities)
  3. Install Node v22 + npm dependencies
  4. Build frontend (`npm run build`)
  5. Set up test database (`.env.example` → `.env`, generate app key)
  6. Run PHPUnit tests (`php artisan test`)
  7. Upload `public/build/` as artifact (14-day retention for deployment)

**Pre-Push Requirements:**
```bash
npm run build     # Verify frontend compiles
php artisan test  # Verify all tests pass
```

---

*Stack analysis: 2026-08-17*
