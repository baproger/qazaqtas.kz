# Codebase Concerns

**Analysis Date:** 2026-08-17

Scope: whole repository, with emphasis on the August 2026 finance rework
(`PLAN-FINANCE.md`, `FINANCE-SPEC.md`, `RELEASE-FINANCE.md`). Every item below was
verified against the code; the "why it matters" line states the concrete damage.

## Severity Ranking (read this first)

| # | Severity | Finding | File |
|---|----------|---------|------|
| 1 | Critical | Deleting a deal silently moves money: its confirmed expenses/payments drop out of every balance, and no one is notified | `app/Models/Deal.php`, `app/Services/FinanceService.php` |
| 2 | High | Cross-company deletion gap in «Все компании» mode for cash receipts and debts | `app/Http/Controllers/CashReceiptController.php`, `app/Http/Controllers/DebtController.php` |
| 3 | High | Money-moving deletions that skip `FinanceAudit::notifyDeleted` (advance payout, purchase payment) | `app/Http/Controllers/PayrollController.php`, `app/Http/Controllers/WarehouseController.php` |
| 4 | High | Analytics duplicates the finance scopes instead of using `FinanceService`, and drops company expenses in two places | `app/Http/Controllers/AnalyticsController.php` |
| 5 | High | Payroll pages have no company isolation: salary, hours, advances can be set for employees of any firm | `app/Http/Controllers/PayrollController.php` |
| 6 | Medium | Positional stage guessing still alive: `slice(-2, 1)` fallback in two money paths | `app/Services/PayrollService.php:262`, `app/Http/Controllers/AnalyticsController.php:143` |
| 7 | Medium | Displayed bonus rate ignores the employee's personal percent — the shown % contradicts the paid bonus | `app/Services/PayrollService.php` |
| 8 | Medium | Unbounded list queries: Analytics loads every payment and expense; Reports and Warehouse have no pagination | `app/Http/Controllers/AnalyticsController.php`, `app/Http/Controllers/ReportController.php`, `app/Http/Controllers/WarehouseController.php` |
| 9 | Medium | N+1 on the payroll page: one debt plan (3 queries) per debtor | `app/Http/Controllers/PayrollController.php` |
| 10 | Medium | Employee contracts are stored on the *default* disk, not an explicit private one | `app/Http/Controllers/UserController.php:241,281` |
| 11 | Medium | The warehouse money migration is irreversible and inflates cash on deploy | `database/migrations/2026_08_16_120000_add_purchase_payment_to_warehouse.php` |
| 12 | Medium | `cash_correction` is a single global setting, silently shared across firms and overwritten wholesale | `app/Http/Controllers/InvoiceController.php:313-340` |
| 13 | Low | All 16 notifications are hardcoded Russian (documented limitation) | `app/Notifications/` |
| 14 | Low | Stale/misleading doc comments about "special stages found by name" | `app/Services/StageTransitionService.php` |
| 15 | Low | Three near-duplicate expense forms in the UI | `resources/js/Components/CompanyExpenseModal.vue`, `resources/js/Components/FinancePanel.vue`, `resources/js/Pages/Finance/MyExpenses.vue` |

---

## Tech Debt

**Duplicated finance scopes in Analytics:**
- Issue: `AnalyticsController::morphCompanyScope()` is a private re-implementation of `FinanceService::scopeCompanyExpenses()` / `scopeCompanyInvoices()`. It is **not** identical: the service scope also matches `expenses.company_id` (company expenses — rent, fuel, internet), the Analytics version does not. `$expBase` (used for `attention.pendingExpenses`) and `$invBase` therefore see a different set of rows than Finance and the accountant's board.
- Files: `app/Http/Controllers/AnalyticsController.php` (`morphCompanyScope`, `$expBase`, `$invBase`), `app/Services/FinanceService.php`
- Impact: the «Требует внимания → заявки ждут проверки» counter under-reports pending company expense requests — the exact records the accountant is supposed to chase. Directly violates FINANCE-SPEC §1.1 ("один источник правды").
- Fix approach: delete `morphCompanyScope`, inject `FinanceService` and use `scopeCompanyExpenses` / `scopeCompanyInvoices` everywhere in this controller.

**Positional stage guessing survives in two money paths:**
- Issue: `DealStage` and `StageTransitionService` were cleaned up to resolve special stages by `stage_type`, but the "на подходе" (act/esf) resolution still falls back to `$stages->slice(-2, 1)->first()` — the second-from-last stage of the funnel — when no stage carries the `act`/`esf` type. In the QAZAQ TAS funnel there is no act stage at all, so the fallback fires on every request.
- Files: `app/Services/PayrollService.php:262` (`dealBreakdown`), `app/Http/Controllers/AnalyticsController.php:143`
- Impact: an arbitrary stage (typically the one just before «Оплата успешно», e.g. «Монтаж») is presented as "money about to land" in the payroll sheet and in per-employee analytics. Reordering the funnel in Settings changes payroll figures with no code change and no warning.
- Fix approach: drop the fallback — `$pendingIds` empty means "no act/esf stage in this funnel, no pending block", exactly as `DealStage::actStage()` already decided.

**Displayed bonus rate omits the personal percent:**
- Issue: `dealBreakdown()` computes the real bonus via `dealBonus(..., $userPercent, ...)` but reports the rate as `effectiveBonusRate($marginPct, $override)` — the third argument (`$userPercent`) is not passed.
- Files: `app/Services/PayrollService.php` (`dealBreakdown`, the `'bonus_rate'` key)
- Impact: for every employee with `users.bonus_percent` set (the "основной режим" per the service docblock), the payroll sheet shows an auto-tier percentage while paying the personal one. The accountant checking a row line by line sees a number that does not reproduce the payout — precisely the argument FINANCE-SPEC §1 tries to prevent.
- Fix approach: pass `self::userBonusPercent($d->responsible_user_id)` as the third argument.

**Stale documentation comments:**
- Issue: the `moveToStage()` docblock still says «Спец-этапы ищутся по названию, а не по позиции», while the body resolves stages via `DealStage::actStage()/esfStage()/ofType()` (i.e. by `stage_type`). `MediaService`'s class docblock claims everything lives in `storage/app/public`, but `storeReceipt()` writes to the private `local` disk.
- Files: `app/Services/StageTransitionService.php`, `app/Services/MediaService.php`
- Impact: the next person "fixing" stage logic to match the comment reintroduces name-based guessing.
- Fix approach: rewrite both docblocks to describe `stage_type` / dual-disk behaviour.

**Three near-duplicate expense forms:**
- Issue: the company-expense form exists as `CompanyExpenseModal.vue` (used from `Finance/Index.vue` and `Finance/ExpensesBoard.vue`), and separate hand-rolled forms posting to `expenses.store` live in `FinancePanel.vue` (deal/project expenses) and `Finance/MyExpenses.vue`.
- Files: `resources/js/Components/CompanyExpenseModal.vue`, `resources/js/Components/FinancePanel.vue`, `resources/js/Pages/Finance/MyExpenses.vue`
- Impact: a new server-side rule (a required field, a new category constraint) has to be mirrored in three places; a miss shows up as a validation error the user cannot resolve from the form they are looking at.
- Fix approach: extract one `ExpenseForm.vue` with a `mode` prop (`company` | `deal` | `mine`).

## Known Bugs

**Deleting a deal moves money without saying so:**
- Symptoms: after an admin deletes a deal, the cash/bank tiles, the cash book and `finance:selfcheck` all shift by the deal's confirmed cash expenses and paid invoice amounts — and all of them shift *consistently*, so the self-check reports "всё сошлось".
- Files: `app/Models/Deal.php` (`booted()` — cascades only to `Project`, `DealStageLog` and the number), `app/Services/FinanceService.php` (`scopeCompanyExpenses`, `scopeCompanyInvoices`), `app/Http/Controllers/DealController.php` (`destroy`, `bulkDestroy`)
- Trigger: `Deal` uses `SoftDeletes`; the finance scopes select expenses/invoices via `Deal::where('company_id', $c)->select('id')`, which excludes trashed deals. The deal's `Expense` and `Invoice` rows stay untouched but silently leave every scope.
- Workaround: none. `bulkDestroy` accepts up to 100 deals at a time, so the jump can be large.
- Fix approach: decide the rule explicitly — either cascade-delete the money records inside a transaction and call `FinanceAudit::notifyDeleted`, or refuse deletion of a deal that has confirmed expenses/payments. Either way, deal deletion must raise the same "удаление денег — событие" notification as `ExpenseController::destroy` (FINANCE-SPEC §1.6).

**Cross-company deletion in «Все компании» mode:**
- Symptoms: a financist attached to firm A, with the header switcher on «Все компании», can delete a cash receipt or a debt belonging to firm B via a direct request.
- Files: `app/Http/Controllers/CashReceiptController.php` (`destroy`), `app/Http/Controllers/DebtController.php` (`assertCompany`)
- Trigger: the guard is `abort_if($companyId && $receipt->company_id && ...)`. In all-companies mode `CurrentCompany::id()` is `0`, so `$companyId` is falsy and the check is skipped entirely. The same happens for records created before company scoping (`company_id === null`).
- Workaround: none. Note that `ExpenseController` and `WarehouseController` do this correctly by calling `User::worksInCompany()`, which does not have the falsy-zero hole.
- Fix approach: replace both checks with `abort_unless($request->user()->worksInCompany($record->company_id), 403)`.

**Money deletions that skip the audit notification:**
- Symptoms: an advance (`PayrollAdjustment` of type `advance`) deleted from the payroll sheet, or a warehouse receipt deleted with its purchase payment, returns money to the cash balance without notifying the CEO/director.
- Files: `app/Http/Controllers/PayrollController.php` (`destroyAdjustment` — `Expense::find(...)?->delete()`), `app/Http/Controllers/WarehouseController.php` (`destroyReceipt` — `$receipt->expense?->delete()`)
- Trigger: both paths call `Expense::delete()` on the model directly instead of going through `ExpenseController::destroy`, which is the only place that calls `FinanceAudit::notifyDeleted` and `NotificationResolver::expenseHandled`.
- Workaround: the deletions are visible in the audit log but nobody is pushed.
- Fix approach: add `FinanceAudit::notifyDeleted(...)` to both, or extract a `FinanceService::deleteExpense(Expense $e)` that owns notification + stock return, and have all three callers use it.

**`destroyAdjustment` has no company or record-ownership check:**
- Symptoms: any financist can delete any payroll adjustment by id, including one for another firm's employee.
- Files: `app/Http/Controllers/PayrollController.php` (`destroyAdjustment`, `updateSalary`, `updateHours`, `storeAdjustment`)
- Trigger: all four methods only check `hasAnyRole(['admin', 'financist'])`; none consults `worksInCompany()` or the target user's companies. `storeAdjustment` even falls back to `$employee->companies()->value('companies.id')` when no company is selected, so an advance can be booked against a firm the actor does not belong to.
- Fix approach: gate all payroll mutations on `worksInCompany()` against the target user's companies, the way `ExpenseController::assertOwnership` does.

## Security Considerations

**Employee contracts rely on the default filesystem disk:**
- Risk: `->store('contracts')` (no disk argument) and the matching `Storage::delete()` / `Storage::exists()` resolve `FILESYSTEM_DISK`. `.env.example` sets `local` (private, `storage/app/private`), so today contracts are private — but a single env change to `public` publishes every employment contract under `public/storage/contracts/…` with a guessable-by-listing path, bypassing the `users.contract` route guard.
- Files: `app/Http/Controllers/UserController.php:241,281,301-305`
- Current mitigation: `FILESYSTEM_DISK=local` in `.env.example`; the download route checks `admin|director|financist|self`.
- Recommendations: pass `'local'` explicitly, exactly as receipts (`MediaService::storeReceipt`), documents (`DocumentController::store`), avatars (`ProfileController::updateAvatar`) and chat attachments already do.

**Private file handling is otherwise sound (verified, no action needed):** receipts, documents, avatars and chat files all go to the private `local` disk and are served through auth-gated routes (`ExpenseController::receipt` with `assertCanSeeReceipt`, `DocumentController::download`, `ProfileController::avatarShow`). Receipts are served `inline` with `X-Content-Type-Options: nosniff`. No `public/storage` symlink leak of private material was found.

**Mass assignment (verified, mostly safe — one soft spot):** `Expense::$fillable` includes `status`, `payment_method`, `confirmed_by`, `confirmed_at`, and `ExpenseRequest` validates `status` and `payment_method` from the request. `ExpenseController::store` overwrites `status` on every branch and `update()` explicitly `unset()`s the four fields plus the morph keys, so there is no live escalation path — but the protection is a chain of controller `unset()`s over a permissive `$fillable`, so any fourth write path to `Expense` (see the two direct-delete paths above) starts unprotected.
- Fix approach: remove `status`, `confirmed_by`, `confirmed_at` from `ExpenseRequest::rules()` and set them only server-side.

**2FA is absent for money roles** — noted by the project itself in `PLAN-FINANCE.md` ("Кандидаты на потом"): `admin` and `financist` can move the entire holding's cash behind a single password.

## Performance Bottlenecks

**Analytics loads full payment and expense history into PHP:**
- Problem: `$payments = Payment::whereHas(...)->get(['amount','payment_date'])` and `$expenses = Expense::where('status','confirmed')...->get(['amount','date'])` fetch *every* row ever, then filter in PHP to build a 3/6/12-month chart. The ABC block does the same (`Deal::whereIn('id', $dealIncome->keys())->get()` over all deals with income).
- Files: `app/Http/Controllers/AnalyticsController.php`
- Cause: the month grouping is done in PHP "for DB portability" (per its own comment) with no date bound on the query.
- Improvement path: add `whereDate(... , '>=', $months->first().'-01')` before `get()`, or group by month in SQL.

**Unpaginated list pages:**
- Problem: `ReportController::deals` renders every non-cancelled deal of the firm as a fully computed row (plus `$workshopByDeal` over all of them); `WarehouseController::index` loads all materials plus *all* confirmed write-off expenses with their eager-loaded morph targets; `PayrollController::index` renders every active employee with a full per-deal breakdown.
- Files: `app/Http/Controllers/ReportController.php`, `app/Http/Controllers/WarehouseController.php`, `app/Http/Controllers/PayrollController.php`
- Cause: these pages are Excel replacements where the owner wants the whole table; only `ExpensesBoardController` paginates (`paginate(30)`).
- Improvement path: at minimum bound the report by the date filter (default to the current year) and paginate warehouse write-offs, which is the fastest-growing table.

**N+1 in the payroll debt plan:**
- Problem: `$debtUsers->mapWithKeys(fn ($uid) => $debtService->planFor((int) $uid, $month))` calls `PayrollService::bonusByUserForMonth()` once per debtor; each call runs its own deals + payments + expenses + materials queries.
- Files: `app/Http/Controllers/PayrollController.php`, `app/Services/EmployeeDebtService.php` (`planFor`)
- Cause: `EmployeeDebtService` exposes only a single-user entry point, although `PayrollService::bonusByUsersForMonth()` (the batched version) already exists and is used a few lines above for `$bonusMonth`.
- Improvement path: add `EmployeeDebtService::plansFor(array $userIds, string $month)` built on `bonusByUsersForMonth` — the batched bonus figures are already computed in the same request.

## Fragile Areas

**`FinanceService::methodBalance` + every page that mirrors it:**
- Files: `app/Services/FinanceService.php`, `app/Http/Controllers/CashBookController.php`, `app/Console/Commands/FinanceSelfcheck.php`, `app/Http/Controllers/AnalyticsController.php`
- Why fragile: the "bank = everything that is not cash, including NULL" rule for payments but "bank = not cash AND NOT NULL" for expenses is asymmetric and repeated verbatim in four files. `FinanceSelfcheck` re-implements the same formula, so a shared mistake in the rule passes the self-check.
- Safe modification: change `methodBalance` only, then re-run `php artisan finance:selfcheck`; treat `CashBookController::flows` as a mandatory second edit site.
- Test coverage: `tests/Feature/MoneyIntegrityTest.php` and `tests/Feature/CashBookTest.php` cover the happy paths but not deletion-driven drift (see below).

**`PayrollService::dealBonus` warehouse split:**
- Files: `app/Services/PayrollService.php`
- Why fragile: the proportional split (`$restRemainder = $remainder - ($sale - $materialCost) + $taxAndPartner * ($sale / $budget)`) recovers tax+partner by subtraction (`$taxAndPartner = $budget - $expense - $remainder`), so any future change to how `$remainder` is composed silently changes bonuses for deals with warehouse goods only.
- Safe modification: always pass the components explicitly rather than deriving them; add a test asserting `tier + warehouse` for a deal with partner % *and* warehouse goods (currently untested — `WarehouseMarkupBonusTest` does not combine them).

**`chargeMonth` reads a stale balance:**
- Files: `app/Services/EmployeeDebtService.php`
- Why fragile: `$debt->balance()` uses the `payments` relation eager-loaded before the loop, while `$debt->fresh()->balance()` is used for the close check. Idempotency rests on the DB unique key (correct, per FINANCE-SPEC §1.4), but concurrent runs can still write a `$take` computed from a stale balance for a *different* month row.
- Test coverage: `tests/Feature/EmployeeDebtTest.php` covers single-run behaviour; there is no concurrent/repeat-run test beyond the unique-key path.

## Scaling Limits

**Single global cash correction:**
- Current capacity: one `Setting` row, `cash_correction`, added to the holding-wide cash balance.
- Limit: `InvoiceController::cashCorrection` recomputes the delta from `companyBalances(null)['cash']` and overwrites the setting. Two admins doing an inventory at the same time, or an inventory taken while a payment is being recorded, produce a wrong delta with no lock and no history beyond one `AuditLog` row. There is also no per-firm correction, while `bank` *is* per-firm.
- Files: `app/Http/Controllers/InvoiceController.php:313-340`, `app/Services/FinanceService.php`
- Scaling path: store corrections as an append-only ledger (date, amount, actor) and sum them, instead of overwriting a single value.

**Deal number uniqueness vs. soft deletes:**
- Current capacity: `Deal::booted()` renames a deleted deal's number to `…#del{id}` to free the unique index.
- Limit: the rename runs in the `deleted` hook without a transaction around the delete; a crash between the two leaves the number occupied and blocks the next deal from being created with it.
- Files: `app/Models/Deal.php`

## Dependencies at Risk

No abandoned or vulnerable packages were found; `RELEASE-FINANCE.md` records a clean `composer audit` as of 16.08.2026. The only structural dependency risk is the reliance on session-held company context (`App\Support\CurrentCompany` reads `session('company_id')`), which makes every company-scoped query untestable and unusable from queued jobs and console commands — `Deal::scopeForCurrentCompany` degrades to a no-op there, so `debts:charge` and `finance:selfcheck` run holding-wide by design.

## Missing Critical Features

**Notification localisation:**
- Problem: all 16 classes under `app/Notifications/` build Russian strings inline; none calls `__()`. The project ships `lang/ru` and `lang/kk` and a full UI translation layer (`UiTranslation`, `DealStageTranslation`, …).
- Blocks: Kazakh-speaking staff get Russian push/e-mail even with the UI in Kazakh. Acknowledged as a known limitation in `PLAN-FINANCE.md:26` and listed under "Кандидаты на потом" — recorded here so it is not rediscovered as a bug.

**Money records for a deleted deal have no recovery UI:** deals are soft-deleted but there is no restore screen, so the orphaned expenses/invoices described above cannot be brought back through the app.

## Migration Risks

**`2026_08_16_120000_add_purchase_payment_to_warehouse.php` — irreversible and money-shifting:**
- What it does: adds `material_receipts.expense_id`, then runs `DB::table('expenses')->whereNotNull('material_id')->update(['payment_method' => null])` — unconditionally, for every historical warehouse write-off.
- Risk: `down()` drops the FK but explicitly does **not** restore `payment_method` ("чем платили за старые списания, не знает никто"). A rollback therefore leaves the database in a third state, neither old nor new.
- Impact: the cash balance jumps upward by the sum of all historical write-offs at deploy time. This is intended and documented (`RELEASE-FINANCE.md` §2) and is reconciled by a one-time manual cash correction (§1 step 5) — but it means the deploy must not be rolled back after step 5, since the correction would then double-count.
- Mitigation in place: `RELEASE-FINANCE.md` step 1 mandates `./scripts/backup.sh` before migrating, and step 4 mandates `finance:selfcheck`.
- Recommendation: record the pre-migration cash balance in the migration output (or a `Setting`) so the required correction is a computed number rather than a manual count.

**Ordering dependency:** the same release also requires manual configuration after migration — assigning the `logistics` stage type and setting `material_markup_percent` / `warehouse_bonus_percent` (`RELEASE-FINANCE.md` §1 steps 6–7). Until step 6 is done the workshop cannot close orders, and until step 7 the warehouse bonus computes against a 0 markup. Neither is enforced or checked by code.

## Test Coverage Gaps

493 test methods across `tests/Feature` — the finance contour is well covered for creation, confirmation, rights and duplicate submission. The gaps are all on the *deletion* and *scope-drift* side:

**Deal deletion vs. money (highest-value missing test):**
- What's not tested: no test deletes a deal that has confirmed expenses and paid invoices and then asserts the cash balance. `tests/Feature/DealBulkDeleteTest.php` covers permissions only; `tests/Feature/MoneyIntegrityTest.php` covers double-submit, over-payment, cancelled invoices and payout double-counting.
- Files: `tests/Feature/MoneyIntegrityTest.php`, `tests/Feature/DealBulkDeleteTest.php`
- Risk: finding #1 above — the balance can move by an arbitrary amount and `finance:selfcheck` still reports success.
- Priority: High

**Cross-company access in «Все компании» mode:**
- What's not tested: `tests/Feature/FinanceOwnershipTest.php` and `tests/Feature/CexAccessTest.php` test isolation with a concrete company selected; no test sets `CurrentCompany::id() === 0` and attempts to delete another firm's `CashReceipt` / `Debt`.
- Risk: finding #2 — an entire class of guards is bypassed in a mode the UI actively offers.
- Priority: High

**Payroll mutations across firms:**
- What's not tested: no test asserts that a financist of firm A cannot set the salary/hours or book an advance for an employee of firm B (`tests/Feature/PayrollTest.php`, `PayrollAdjustmentTest.php`, `PayrollWorkHoursTest.php` all use a single firm).
- Risk: finding #5.
- Priority: Medium

**Advance / purchase deletion notifications:**
- What's not tested: nothing asserts that deleting an advance adjustment or a paid warehouse receipt notifies the CEO/director, while `FinanceAudit` behaviour *is* asserted for expenses and payments.
- Risk: finding #3 — a silent money return.
- Priority: Medium

**Bonus display vs. bonus paid:** no test asserts that the `bonus_rate` shown in `dealBreakdown()` reproduces the `bonus` on the same row when `users.bonus_percent` is set (finding #7). Priority: Medium.

**Combined partner % + warehouse markup bonus:** `WarehouseMarkupBonusTest` and `DealPartnerShareTest` each exercise one lever; the proportional split in `dealBonus()` is only stressed when both are present. Priority: Medium.

---

*Concerns audit: 2026-08-17*
