# External Integrations

**Analysis Date:** 2026-08-17

## Database

**Primary:**
- MySQL 5.7+ (configured in `.env` as `DB_CONNECTION=mysql`)
- Connection: Host `DB_HOST` (default 127.0.0.1), port `DB_PORT` (default 3306)
- Database: `DB_DATABASE` (default `qazaqtas_erp`)
- Credentials: `DB_USERNAME`, `DB_PASSWORD`
- ORM: Laravel Eloquent (via `laravel/framework`)
- Migrations: Auto-run on production deployment (`php artisan migrate --force`)
- Seeders: Initial roles, company data, stages, materials, settings via `php artisan db:seed`

**Key Tables:**
- `users` - Employees with roles, language preference, workshop access
- `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` - RBAC via spatie/laravel-permission
- `deals`, `deal_stages`, `deal_stage_logs` - Sales pipeline and execution tracking
- `projects`, `project_stages` - Workshop execution (Формовка → Шлифовка → Упаковка → Отправка) per city
- `products`, `product_categories`, `product_translations` - Storefront catalog
- `orders`, `order_items` - Public website orders
- `expenses`, `invoices`, `receipts` - Financial records
- `materials`, `material_receipts` - Inventory/warehouse
- `employee_debts`, `employee_debt_payments` - Employee loan tracking
- `sessions` (if using database driver) - User session storage
- `cache` (if using database driver) - Cache entries
- `push_subscriptions` - Web push notification endpoints
- `ui_translations` - User-editable UI text overrides
- All tables support soft deletes where applicable (cancelled orders, deleted expenses)

**Backups:**
- Manual: `./scripts/backup.sh /var/backups/qazaqtas` - Creates dated SQL dump + tar.gz of `storage/app/public`
- Scheduled: Typically via cron: `0 3 * * * /var/www/qazaqtas/scripts/backup.sh /var/backups/qazaqtas`
- Retention: 14 days (configurable via `BACKUP_KEEP_DAYS` in script)
- Rationale: Photos, 3D models in `storage/app/public` are unversioned; backups are only recovery method

## Data Storage & Filesystem

**File Storage:**
- **Primary disk:** `local` (private, `storage/app/private/`)
- **Public disk:** `public` (web-accessible at `/storage/`, root `storage/app/public/`)
- **S3 Support:** Optional; credentials in `.env` as `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`

**Uploaded Files (Public Disk):**
- Product photos: `catalog/{product_id}/` - Original + 1600px + 600px preview
- Category images: `categories/{category_id}/` - 1600×1600 PNG/WebP with transparency
- 3D models: `catalog/{product_id}/models/` - GLB, GLTF, OBJ with textures
- WebP copies: Auto-generated alongside JPEG/PNG (half file size, lossless)
- Site project photos: `site_projects/{id}/`
- Documents: `documents/{deal_id}/` - Attached files to deals (contracts, acts, invoices)

**File Access Control:**
- `app/Services/MediaService` - Centralized upload/resize/delete; validates ownership by folder path before deletion
- Attachment deletion checks `path` matches expected location to prevent unauthorized deletion
- Public files cached indefinitely (`Cache-Control: public, immutable, 1y`) since names include content hash

**Storage Requirements:**
- Estimated: 1-5GB for typical deployment (photos + 3D models)
- Nginx config (production):
  ```nginx
  location /build/ { expires 1y; add_header Cache-Control "public, immutable"; }
  location /storage/ { expires 30d; add_header Cache-Control "public"; }
  ```

## Authentication & Authorization

**Mechanism:**
- Framework: Laravel Sanctum (via `laravel/sanctum: ^4.0`)
- Session-based: Browser/Inertia requests use Laravel session + cookie
- Token-based: Mobile/API clients use Sanctum tokens stored in `personal_access_tokens` table
- Password: Hashed via bcrypt (configurable rounds in `.env` as `BCRYPT_ROUNDS`, default 12)

**RBAC (Role-Based Access Control):**
- Package: spatie/laravel-permission (^8.1)
- Configuration: `config/permission.php`
- Roles: `admin`, `director`, `financist`, `manager`, `employee`, `designer` (технолог), `supplier` (снабженец), `lawyer`, `cook`
- Permissions: Fine-grained per model and action (e.g., `product.create`, `deal.update`, `project.view`)
- Admin Protection: Last active admin cannot be deactivated or demoted; only admin can assign admin role
- Gate Override: `Gate::before()` gives admin bypass for all authorization checks (`AdminGate` middleware)
- Workshop Access: Stored in `users.workshops` JSON; employees see only deals/projects for assigned workshops (Шымкент/Алматы/Тараз)

**Authorization Policies:**
- `ProductCategoryPolicy` - Requires `product.update` for category edits
- `DealPolicy` - Only deal creator and financist/admin can edit
- `ProjectPolicy` - Workshop access gating via `assertWorkshopAccess`
- `UserPolicy` - Admin/director/financist can view all; users see own profile only
- Scoped policies via middleware: `worksInCompany` filters to single company (currently QAZAQ TAS QT)

**Auth Endpoints:**
- Login: `POST /login` (form-based, redirects on success)
- Logout: `POST /logout`
- Profile: `GET /profile`, `PUT /profile` (user settings)
- Password reset: `POST /forgot-password`, `POST /reset-password`
- No public API authentication currently deployed (Sanctum configured but unused)

## Mail & Notifications

**Mailer:**
- Default: Log transport (`MAIL_MAILER=log` in `.env`); writes to log file for development
- Production: Typically SMTP; configure `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
- Alternative mailers configured but unused: Postmark, Resend, SES, Sendmail

**Outgoing Emails:**
- Notifications sent via Illuminate notifications system
- From address: `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` from `.env`
- Queued: Jobs stored in `notifications` queue (uses sync/database depending on env)

**Notification Types (In-App & Email):**
- Quote deadline reminder - `pre-deals:notify-quote-deadline` (09:00 daily)
- Birthday greeting - `users:notify-birthdays` (09:00 daily)
- Overdue tasks - `tasks:notify-overdue` (hourly)
- Stale expense requests - `expenses:notify-stale` (09:30 daily; homogenized after 3 days)
- Expense deletions - `ExpenseDeletedNotification` to admin/director
- Deal creation/stage changes - Various events notify responsible roles
- Employee debt charges - `debts:charge` (1st of month 09:00)
- In-app only: Kolokol/bell notifications, chat messages (via web-push or in-app notifications table)

**Push Notification Subscriptions:**
- Stored in: `push_subscriptions` table (user_id, endpoint, auth, p256dh, created_at)
- Client-side: `public/sw.js` service worker handles browser subscription
- Server-side: `PushService` uses `minishlink/web-push` to send notifications
- Configuration: VAPID keys in `.env` as `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` (generate via openssl if needed)
- Delivery: Via Web Push API (browser must support Service Workers)
- Fallback: If VAPID keys missing, push silently fails; users see in-app notifications only

## Chat & Real-Time Features

**Chat System:**
- Stored: Messages in `chat_messages`, reactions in `chat_message_reactions`, files in `chat_files`
- Groups: `chat_groups` with members and visibility rules
- Deal-linked chat: Linked to `deals.id` for deal-specific conversations
- Features: Mentions, reactions, quoted replies, message editing, deletion
- Real-time updates: Polled from frontend (no WebSocket); not polled from background tabs (saves bandwidth)
- Unread count: `notifications` table with `type=chat_message`; marked read after list view

**Sound & Notifications:**
- Sound: Plays on new message (browser audio API)
- Web Push: Via service worker for tab visibility changes
- Fallback: In-app bell icon with red badge on new unread messages

## SMS & Communication (Not Currently Integrated)

**WhatsApp:**
- Links only: Configured in settings as `whatsapp_number`; no programmatic integration
- Order flow: "Заказать в WhatsApp" button generates templated text, redirects to WhatsApp Web/App
- Contact: Button on storefront communicates via WhatsApp channel (manual only)

**Phone:**
- Contacts stored in settings (headquarters, regional offices)
- No SMS or voice call integration
- Incoming calls not tracked in CRM

**Instagram:**
- URL stored in settings; contact link only, no API integration

## Webhooks & External Callbacks

**Incoming:**
- GitHub webhook for auto-deployment: Plesk Git settings receive `push` events
- No other incoming webhooks configured

**Outgoing:**
- None currently configured
- Potential future integrations: Financial reporting to accounting systems, order export to ERP partners

## Monitoring & Observability

**Logging:**
- Default: Single log file (`storage/logs/laravel.log`)
- Stack: `LOG_CHANNEL=stack` with single daily log
- Level: `LOG_LEVEL=debug` (configurable)
- Framework: Laravel's default logging via Monolog
- Audit log: Separate audit entries in database for cash corrections, financial deletions (via `App\Models\Audit`)

**Error Tracking:**
- No dedicated service (Sentry/Rollbar) configured
- Errors logged locally; developer must monitor logs
- In production: `APP_DEBUG=false` hides sensitive details; basic error page shown to users

**Performance Monitoring:**
- No APM (Application Performance Monitoring) service integrated
- Caching strategy monitored manually (query counts, response times)
- OPcache enabled on production for PHP-level optimization

**Health Checks:**
- Custom: `php artisan finance:selfcheck [--company=QT]` - Validates financial data consistency
- Manual: Comparison of expected vs. actual cash, debt totals, payroll calculations
- No automated health check endpoints exposed

## CI/CD Webhooks & Automation

**GitHub Actions:**
- Workflow: `.github/workflows/ci.yml` on push to `master`/`main` and all PRs
- Artifact upload: `public/build/` (frontend compiled assets, 14-day retention)
- No automatic deployment to production (manual Plesk webhook trigger or Git pull)

**Plesk Integration:**
- Git webhook: `push` events trigger automatic `git pull` + deployment steps
- Deployment steps: `composer install --no-dev`, `php artisan migrate --force`, `php artisan optimize`
- No CI status checks enforced (PRs can merge without passing CI)

## Security

**Headers (via `App\Http\Middleware\SecureHeaders`):**
- `X-Frame-Options: DENY` - Prevent clickjacking
- `X-Content-Type-Options: nosniff` - Disable MIME type sniffing
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy` - Restrict browser capabilities
- `Strict-Transport-Security` (HSTS) - Enforce HTTPS (production only)
- `Content-Security-Policy-Report-Only` - Monitor CSP violations without blocking (waiting for review period before enforcement)

**Content Security Policy (Report-Only Mode):**
- Vue injects inline styles (required)
- Gradient background uses `data:` URIs (required)
- External fonts from `fonts.bunny.net` (whitelisted)
- Full enforcement deferred pending 1-week violation review

**Input Validation:**
- Laravel validation rules on all forms (server-side + Vue client-side)
- Sanitization: No HTML input accepted (no `v-html`); all user text treated as plain strings except menu SVG icons (hardcoded)
- File uploads: Type checking by extension + MIME type; ownership path validation on delete

**Sensitive Data:**
- `.env` contains secrets; never committed to git
- Database passwords, API keys in `.env` only
- Backup script excludes `.env`; `.git/` and `vendor/` not backed up (repo-managed)
- Admin password: Set via `ADMIN_PASSWORD` env var or randomly generated on seed

**Rate Limiting:**
- Login form: Throttled (default Laravel)
- Public forms (contact, order): Throttled to prevent spam
- Plesk WAF may impose additional IP-based rate limits

**CORS:**
- Framework default: No explicit CORS config; same-origin only
- External links use `rel="noopener noreferrer"` to prevent referrer leaks
- No cross-origin API calls within application

## Environment Configuration

**Required Environment Variables:**

| Variable | Purpose | Example |
|---|---|---|
| `APP_NAME` | Application name | `QAZAQ TAS ERP` |
| `APP_ENV` | Environment mode | `production` |
| `APP_DEBUG` | Debug mode | `false` |
| `APP_URL` | Site URL | `https://erp.qazaqtas.kz` |
| `APP_KEY` | Encryption key | Auto-generated |
| `APP_TIMEZONE` | Timezone | `Asia/Almaty` |
| `ADMIN_PASSWORD` | Initial admin password | Set before seed |
| `DB_*` | Database connection | MySQL credentials |
| `SESSION_DRIVER` | Session backend | `file` (prod), `database` (dev) |
| `QUEUE_CONNECTION` | Queue driver | `sync` (prod), `database` (dev) |
| `CACHE_STORE` | Cache driver | `file` (prod), `database` (dev) |
| `MAIL_MAILER` | Mail service | `log` (default), `smtp` (prod) |
| `VAPID_*` | Web Push keys | OpenSSL-generated (optional) |

**Optional Variables:**
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET` - S3 storage (unused)
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` - SMTP configuration
- `REDIS_*` - Redis connection (unused; Queue/Cache configured for file/database)

**Secrets Location:**
- **Development:** `.env` file (local-only, not committed)
- **Production:** `.env` file on server (managed manually; not pulled from git)
- **CI/CD:** GitHub Secrets (unused; actions don't deploy to production automatically)

**Setting Overrides:**
- `settings` table stores runtime config: company timezone, currencies, bonus rates, predeal margin threshold, etc.
- Managed via ERP UI → Settings; changes take effect immediately (cached per request via `Setting` facade)
- Example settings: `tax_percent`, `predeal_min_margin`, `bonus_by_margin_steps`, `cash_correction` (admin-only journal entries)

## Deployment & Infrastructure

**Hosting Platform:**
- Plesk (shared hosting with automatic Git integration)
- PHP 8.3.30 (alt-php via Plesk)
- MySQL database (shared or VPS)
- Nginx or Apache (configurable in Plesk)
- Outbound internet access required (Composer package downloads, SMTP if external mail service)

**Deployment Process:**
1. Developer pushes to `master` branch on GitHub
2. GitHub Actions runs CI (tests, audit, build)
3. Plesk Git webhook triggered by push
4. Plesk auto-pulls latest code
5. Deployment script runs: `composer install --no-dev`, `migrate --force`, `optimize`
6. New code live within seconds

**Asset Pipeline:**
- Frontend: Built locally (`npm run build`) or via GitHub Actions; pushed to repo or downloaded from artifact
- Hashed filenames: Vite adds content hash (e.g., `app.abc123.js`); safe for infinite caching
- Cache busting: Built-in via hash; no manual cache clearing needed

**Performance Optimizations:**
- OPcache: Enabled on production (php.ini)
- Config/route/view cache: Generated via `php artisan optimize`
- File cache: Preferred for queue (sync) and cache (file) on Plesk
- Query optimization: Indexed lookups, N+1 detection in tests
- Frontend: Code-split per page, lazy-load 3D libraries, WebP images, minified assets

## Analytics & Tracking

**Built-in:**
- Financial analytics dashboard (charts, KPIs for revenue/expenses/margins)
- Payroll analytics (employee performance, bonuses)
- Sales funnel (pre-deals → deals → won deals)
- No external analytics service (Google Analytics) integrated

**Reporting:**
- Summary reports (Excel export) for deals, payroll, financials
- Audits available via ERP UI (admin-only)
- No integration with business intelligence tools

## Third-Party APIs & Marketplace Integrations

**Not Integrated:**
- No e-commerce marketplace integrations (Shopify, WooCommerce, etc.)
- No CRM system integrations
- No accounting software integrations (though finance module mimics accounting records)
- No SMS/Twilio integration
- No payment gateway (Stripe, PayPal, etc.); cash/bank payments tracked manually

**Potential Integrations (Not Implemented):**
- Telegram bot for notifications
- Slack for team alerts
- Payment processor for automated receipts
- Email marketing (Mailchimp, SendGrid)
- Document signing (DocuSign)

---

*Integration audit: 2026-08-17*
