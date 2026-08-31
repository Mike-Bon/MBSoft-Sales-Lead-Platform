# FY2026 Fiscal Performance Extension — Implementation Report

**Status:** Implemented, all tests green. **NOT committed, tagged, pushed, deployed, or populated with production data** — awaiting Mike's review per spec §14.

**Baseline:** `v2.0.3` (`5c3dec5`). **Test count:** 1079 → **1152 passed, 0 failures** (73 new). **Pint:** passes.

> **Units = weighted/fractional:** `target_units` / `actual_units` `decimal(16,2)` nullable; `target_revenue` / `actual_revenue` `decimal(14,2)` (matching `targets.target_amount`). Importer parses units with `parseDecimal` (accepts `0`, `278`, `278.4`, `0.25`; rejects negatives, text, `NaN`/`Infinity`, scientific, formulas). `FiscalPerformanceService` carries units as `?float` end-to-end — no `(int)` casts, no `ceil`; `requiredMonthlyUnits` is a fractional average. 4 migrations edited in place (never released).
>
> **Master-data tooling:** `App\Support\Performance\ReportingUnitCatalog` (44 canonical units, names+codes only), `php artisan performance:seed-reporting-units` (idempotent, fails closed), `php artisan performance:show-teams` (read-only, for the `teams.code` backfill). See [FY2026_WORKBOOK_MAPPING.md](FY2026_WORKBOOK_MAPPING.md).
>
> **Reconciliation:** FY plan revenue — import ledger target **₱46,179,159.31** (workbook raw float ₱46,179,159.485; raw Δ −₱0.175 / displayed −₱0.18, from row-level 2dp quantisation of 528 lines — **accepted**, no data altered). December actual revenue **₱2,148,505.52** exact. Before import: `teams.code` backfill + `reporting_units` seed (both prepared via `performance:show-teams` / `performance:seed-reporting-units`, not executed).

---

## 1. Architecture implemented

A **small additive extension** (inspection Option B), fully separate from the CRM sales-pipeline performance stack.

Two parallel, non-overlapping performance systems now exist:

| | Sales-pipeline performance (unchanged) | Operational fiscal performance (new) |
|---|---|---|
| Source of truth | CRM `opportunities` (stage = Closed Won, `closed_at`) | `performance_plan_lines` + `performance_actual_lines` (imported from the corporate workbook) |
| Service | `App\Services\PerformanceService` | `App\Services\FiscalPerformanceService` |
| Target model | `App\Models\Target` (arbitrary period, single `target_amount`) | `performance_plan_lines` (monthly phased, Units + Revenue) |
| Period model | arbitrary `period_start`/`period_end` | fiscal year Dec 1 – Nov 30, 12 fiscal-month ordinals |
| "Run rate" | day-based (`PerformanceService`) | fiscal-month-based (`requiredMonthlyRevenue` / `requiredMonthlyUnits`) |
| AI tool | `GetPerformanceSummaryTool` etc. (unchanged) | `GetFiscalPerformanceTool` (new, Performance agent only) |
| Screen | `/performance` (unchanged) | `/performance/fiscal` (new) |

Nothing in `PerformanceService`, `Target`, `TargetService`, `Opportunity`, `Organization`, the existing performance AI tools, CRM scoping, Market Intelligence, `ProspectLeadCreationService`, `GeminiProvider`, `Agent`, or `AssistantService` was modified. Branches/reporting locations are **never** `Organization` rows; historical operational actuals are **never** `Opportunity` rows.

---

## 2. Migrations / tables added (4)

All under `database/migrations/2026_09_02_*`. All additive `CREATE TABLE`; no existing migration touched; no data transformation of any existing table.

1. **`reporting_units`** — `id`, `team_id` FK (`restrictOnDelete`), `code`, `name`, `status` (default `active`), `sort_order` (nullable), timestamps. `UNIQUE(team_id, code)`; index on `status`.
2. **`performance_imports`** — batch provenance: `type` (`plan`/`actual`), `source_filename`, `fiscal_year` (nullable), `status` (`validating`/`completed`/`failed`), `accepted_rows`, `rejected_rows`, `dry_run`, `summary`, `imported_by` FK users (`nullOnDelete`), `started_at`, `completed_at`, timestamps.
3. **`performance_plan_lines`** — `fiscal_year`, `period_month` (fiscal ordinal 1–12), `team_id` FK, `reporting_unit_id` FK **nullable**, `target_units` (nullable), `target_revenue` `decimal(16,2)`, `currency` (default `PHP`), `source`, `imported_at`, `performance_import_id` FK (`nullOnDelete`), timestamps. Indexes `(fiscal_year, team_id)`, `(fiscal_year, period_month)` + two partial unique indexes (see §6).
4. **`performance_actual_lines`** — same shape with `actual_units` / `actual_revenue`; `reporting_unit_id` **NOT NULL**; single named `UNIQUE(fiscal_year, period_month, team_id, reporting_unit_id)`.

---

## 3. Models / services / support classes added

**Models:** `ReportingUnit`, `PerformanceImport`, `PerformancePlanLine`, `PerformanceActualLine`.
`ReportingUnit` `$fillable` deliberately excludes `team_id` (set only via relationship/factory). Plan/Actual line models are `$guarded = ['id']` (only the importer writes them, server-side, from resolved ids).

**Enums:** `ReportingUnitStatus`, `PerformanceImportType`, `PerformanceImportStatus`.

**Services:**
- `App\Services\FiscalPerformanceService` — all fiscal figures (§8).
- `App\Services\Performance\PerformanceImportService` — validate-first CSV importer (§7).

**Support:**
- `App\Support\FiscalYear` — fiscal-calendar value object (§4).
- `App\Support\Csv` — minimal, dependency-free CSV reader (BOM strip, header lower-case, 1-based line numbers).
- `App\Support\Performance\ImportResult` — readonly import outcome.
- `App\Support\Performance\FiscalPerformanceSnapshot` — readonly ~30-field result object with `toArray()`.

**Console:** `performance:import-plan`, `performance:import-actuals` (`{file} {--dry-run} {--as=email}`).

**HTTP:** `App\Http\Controllers\Performance\FiscalPerformanceController@index` → `resources/views/performance/fiscal/index.blade.php`.

**AI:** `App\Services\Ai\Tools\GetFiscalPerformanceTool` (`get_fiscal_performance`).

**Config:** `config/performance.php` — `fiscal_year_start_month` (env `PERFORMANCE_FISCAL_YEAR_START_MONTH`, default 12); `import.reject_negative_values` (default true).

---

## 4. `FiscalYear` behaviour

- `FiscalYear::of(2026)` → Dec 1 2025 … Nov 30 2026. `label()` = `FY2026`.
- Fiscal-month ordinals: **1 = December, 2 = January, … 12 = November.** `calendarForOrdinal()`, `ordinalStart/End()`, `ordinalName()`, `ordinalFor(date)`, `months()` (12-row list).
- `monthsElapsedAsOf($asOf)` — fiscal months **begun** as of a date (0–12). As of 2026-08-31, FY2026 → **9** (Dec–Aug).
- `completedMonthsAsOf($asOf)` — fiscal months fully **ended**. 2026-08-15 → 8; 2026-08-31 → 9.
- `remainingMonthsAfter($n)` = `12 − n`. `previous()` → FY2025. `containing($date)` resolves the FY for any date.
- A `start_month = 1` (calendar-year) configuration is supported and tested.
- **Never reads the clock internally** where a date can be supplied; every public entry point takes an explicit `?Carbon $asOf` and only defaults to `now()` at the boundary.
- Rejects invalid input (year outside 2000–2100, start month outside 1–12).

Unit-tested in `tests/Unit/Support/FiscalYearTest.php` (11 tests) — boundary dates, both calendar years, ordinal mapping, elapsed/completed counts.

---

## 5. Reporting-unit identity design

- A `ReportingUnit` **belongs to a `Team`** and is **never** an `Organization`. It is an internal reporting dimension (branch / account / location), not a CRM customer.
- `code` is the **stable import identity**. Uniqueness is `(team_id, code)` — the same code may recur under different teams but resolves unambiguously because every import row carries `team_code` + `reporting_unit_code`.
- **No fuzzy matching, ever.** `reporting_unit_code` must match `reporting_units.code` exactly (trim + case-fold only). Display-name variants (`E MALL` vs `E-MALL`, `METRO AYALA CEBU` vs `METRO AYALA`) are reconciled **once, by a human**, when the unit is created — they are not resolved at import time.
- `status` (`active`/`inactive`) + nullable `sort_order` for presentation. No seed data and **no guessed code↔branch mappings** are shipped (§11).

---

## 6. PostgreSQL-safe uniqueness / idempotency design

`performance_plan_lines.reporting_unit_id` is nullable (a team-level budget line where the workbook does not phase down to branches). A plain composite `UNIQUE(fiscal_year, period_month, team_id, reporting_unit_id)` is **unsafe on PostgreSQL**: Postgres treats `NULL` as distinct, so two team-level lines for the same (year, month, team) would both be accepted.

Solution — **two partial unique indexes** (raw `DB::statement`, supported by both PostgreSQL and SQLite):

```sql
CREATE UNIQUE INDEX performance_plan_lines_unit_unique
  ON performance_plan_lines (fiscal_year, period_month, team_id, reporting_unit_id)
  WHERE reporting_unit_id IS NOT NULL;   -- branch-level lines

CREATE UNIQUE INDEX performance_plan_lines_team_unique
  ON performance_plan_lines (fiscal_year, period_month, team_id)
  WHERE reporting_unit_id IS NULL;       -- the single team-level line
```

`performance_actual_lines.reporting_unit_id` is **NOT NULL** (an actual is always attributed to a branch), so it uses a single ordinary named `UNIQUE`.

**Idempotency:** `PerformanceImportService::upsert()` calls `updateOrCreate($businessKey, $values)` on the same key. Laravel emits `WHERE reporting_unit_id IS NULL` correctly for the null branch. Re-importing a file **updates** existing lines; it never duplicates and never creates opportunities. The DB indexes are the backstop if a write bypasses the service.

Tested in `tests/Feature/Performance/FiscalSchemaTest.php` (7) — including "a second team-level plan line for the same period is rejected" and "actuals require a reporting unit".

---

## 7. Import validation / upsert behaviour

`PerformanceImportService::import(type, csvPath, ?importer, dryRun)`:

CSV contracts (normalised CSV only — **no spreadsheet dependency**):
```
PLAN:    fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue
ACTUAL:  fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue
```

1. **Whole file validated before any write.** One bad row ⇒ the entire file is rejected, `performance_imports` row marked `failed`, **nothing written** (no partial import).
2. Per-row checks, all producing **row-numbered** errors (`line 7: …`):
   - `fiscal_year` a year 2000–2100;
   - `period_month` a fiscal ordinal 1–12;
   - `team_code` present and matching `teams.code` exactly — **unknown team rejected**;
   - `reporting_unit_code` matching `reporting_units.code` exactly; **unknown unit rejected**; a unit belonging to **another team** rejected with a distinct message; blank allowed for a PLAN team-level line, **required** for every ACTUAL row;
   - `*_units` — optional; blank ⇒ `null`; must be a whole number; **negative rejected** (config; see below);
   - `*_revenue` — **required**; must be numeric; **negative rejected**.
3. **In-file duplicate keys** detected → `line N: duplicate of line M`.
4. **Idempotent upsert** on `(fiscal_year, period_month, team_id, reporting_unit_id)` inside a single `DB::transaction`.
5. **Dry-run mode** — full validation, zero writes, `performance_imports` row marked `completed` / `dry_run = true`.
6. **Provenance** — each line stores `source` (filename), `imported_at`, `performance_import_id`; the batch row records `accepted_rows` / `rejected_rows` / `imported_by`.
7. **Negative-value policy: REJECT** (`config('performance.import.reject_negative_values')`, default `true`). Rationale: a correction is a re-import of the corrected positive figure — idempotent upsert overwrites. Documented in the migration and service docblocks.
8. **CSV-injection safe** — no CSV cell text is ever persisted or evaluated. Only resolved DB ids, parsed numbers, and the operator-supplied filename are stored. A `=SUM(...)`, `+`, `-`, `@…` cell fails the numeric regex (or the exact code lookup) and is rejected — never executed.

`performance:import-plan` / `-actuals`: CLI-only, run by the operator on the server. `--as=email` attributes the batch to a **Manager** account for the audit trail; a non-Manager email fails with exit 1 and `… is not a Manager`.

Tested: `PerformanceImportServiceTest` (12), `ImportPerformanceCommandsTest` (5).

---

## 8. `FiscalPerformanceService` formulas

Computed **only** from `performance_plan_lines` + `performance_actual_lines`. No CRM opportunity is ever read (asserted `assertDatabaseCount('opportunities', 0)` in multiple tests).

Given `through = FiscalYear::of($fy)->monthsElapsedAsOf($asOf)`:

| Figure | Definition |
|---|---|
| `fyTargetRevenue` / `fyTargetUnits` | Σ plan lines for the FY (all 12 months), in scope |
| `ytdPhasedTargetRevenue` / `…Units` | Σ plan lines with `period_month ≤ through` |
| `ytdActualRevenue` / `…Units` | Σ actual lines with `period_month ≤ through` — **only lines that exist**; a month with no imported actuals contributes nothing and is **not invented** |
| `ytdRevenueVariance` | `ytdActualRevenue − ytdPhasedTargetRevenue` |
| `ytdUnitVariance` | `(ytdActualUnits ?? 0) − (ytdPhasedTargetUnits ?? 0)`, `null` only if both null |
| **`ytdTargetAttainmentPct`** | `ytdActualRevenue / ytdPhasedTargetRevenue × 100` — **`null`** when the phased-through-month target is 0 |
| **`fyAttainmentToDatePct`** | `ytdActualRevenue / fyTargetRevenue × 100` — **`null`** when the full-FY target is 0 |
| `remainingFyRevenueTarget` | `max(fyTargetRevenue − ytdActualRevenue, 0)` |
| `remainingFyUnitTarget` | `max(fyTargetUnits − ytdActualUnits, 0)`, `null` if no unit target |
| `remainingFiscalMonths` | `12 − through` |
| `requiredMonthlyRevenue` | `remainingFyRevenueTarget / remainingFiscalMonths` (`null` if 0 months left). **Not** called "run rate". |
| `requiredMonthlyUnits` | `ceil(remainingFyUnitTarget / remainingFiscalMonths)` |
| `revenuePerUnitActual` / `…Target` | revenue ÷ units (`null` when units 0/null) |
| `actualMonthsLoaded` / `lastActualPeriodMonth` / `actualsComplete` | actual-coverage introspection (`actualsComplete` = `through > 0 && monthsLoaded === through`) |
| `monthlyTrend` | 12 rows: ordinal, name, calendar y/m, target/actual units & revenue, `has_actual` |
| `teamTotals` | per-team roll-up (organisation scope only), sorted most-behind-first, filtered to teams with a target or an actual |
| `reportingUnitBreakdown` | per-unit roll-up (when not already unit-scoped), `below_phased_target` flag, sorted by variance |
| `priorYear` | same snapshot for FY−1 at the equivalent fiscal-month horizon — **`null` if FY−1 has no plan/actual rows** (never fabricated) |

Roll-up: `sum()` aggregates every row matching the scope filter. For FY2026 all rows are unit-level, so `Σ(units) = team total = org total` holds (reconciliation, §15).

`forOrganisation()` / `forTeam(Team)` / `forReportingUnit(ReportingUnit)`; every entry point takes explicit `?Carbon $asOf`.

---

## 9. Difference between "YTD Target Attainment" and "FY Attainment to Date"

Deliberately **two distinct, separately-named metrics** — the workbook's single "YTD Percentage" is **not** reproduced.

- **`YTD Target Attainment`** = `YTD Actual Revenue ÷ Σ(phased monthly target revenue through the reporting month)`.
  "Given how far into the year we are, are we hitting the plan **for the months elapsed**?" Denominator grows month by month; `null` before any phased target exists.

- **`FY Attainment to Date`** = `YTD Actual Revenue ÷ Full-year target revenue`.
  "How much of the **whole-year** goal is banked so far?" Denominator is fixed; this figure is expected to be well below 100 % mid-year.

Worked example (FY2026, as of 2026-08-31 = 9 fiscal months, CEC test team): phased Dec–Aug target = ₱13,500,000; actual = ₱12,150,000 ⇒ **YTD Target Attainment = 90.00 %**. Full-FY CEC target = ₱23,400,000 ⇒ **FY Attainment to Date = 51.92 %**. Both are shown, labelled, side by side; the UI asserts it never renders the bare string "YTD %".

---

## 10. Team / reporting-unit authorization

Reuses the existing `PerformanceAuthorizer` (not a Policy):

| Actor | Organisation view | Team view | Reporting-unit view |
|---|---|---|---|
| Manager | ✅ all teams + org roll-up | ✅ any team | ✅ any unit |
| Team Head | ❌ (403 / redirected to own team) | ✅ **only `actor.team_id`** | ✅ only units of own team |
| Team Member | ❌ | ✅ read own team only | ✅ own team's units only |

- Controller: no `team_id` ⇒ a non-Manager is scoped server-side to `actor.team_id`; an explicit out-of-scope `team_id`/`reporting_unit_id` ⇒ `authorizeTeam()` throws → **403**.
- A Team Head **cannot** see another team's operational performance, and the org-wide `teamTotals` / cross-team breakdown are only built for `scopeType === 'organisation'` (Manager only).
- External/AI origin never widens scope — the model cannot pick an arbitrary team (see §11 of the CLAUDE.md V2 rules, applied here as defense-in-depth).

Tested: `FiscalPerformanceControllerTest` (8), `GetFiscalPerformanceToolTest` (10 — full authz matrix).

---

## 11. AI tool behaviour — `get_fiscal_performance`

- **One read-only tool.** Registered **only** with the Performance agent (`AppServiceProvider`); added to the negative lists of Sales, Communication, Cost-to-Serve, Business Development, and Market Intelligence agents, and to the `V2FreezeInvariantsTest` forbidden list.
- Parameters: `fiscal_year` (default = FY containing `as_of`), `as_of` (`YYYY-MM-DD`, default today), `team_id`, `reporting_unit_id`.
- Scope resolution is **server-side from the authenticated actor**:
  - `reporting_unit_id` given → resolve unit → `authorizeTeam(actor, unit->team)` → `forReportingUnit`.
  - else `team_id` given, or non-Manager → `authorizeTeam` → `forTeam` (a non-Manager is forced to `actor.team_id`).
  - else Manager with nothing → `authorizeOrganisation` → `forOrganisation`.
- Output is the snapshot `toArray()`, with `reporting_unit_breakdown` capped to the 15 worst-variance rows, prior-year nested arrays stripped, and an appended `note` explaining that these are **operational** figures (not CRM pipeline) and defining both attainment metrics.
- All numbers are **deterministic** — computed by `FiscalPerformanceService`, never by the model.
- `AuthorizationException` / `ValidationException` are caught by `Agent` and returned as a safe `tool_result` (no stack traces, no data leak).

---

## 12. UI changes

- New route `GET /performance/fiscal` → `performance.fiscal.index` (inside the existing `performance` prefix/auth group).
- New sidebar link "Fiscal Year Performance" (`calendar-days` icon) under the **Performance** group, directly after "Performance". The existing "Performance" and "Targets" links are unchanged.
- New Blade view `performance/fiscal/index.blade.php` (`<x-layouts.app>`):
  - Header "Fiscal Year Performance" + an "Operational Performance" callout linking back to the CRM-pipeline `/performance` screen, stating the two are different.
  - GET filter form: FY select (`current+1 … current−3`), as-of date, team select (all for Manager / own for others), reporting-unit select; `onchange` auto-submit.
  - KPI grid showing **both** "YTD Target Attainment" **and** "FY Attainment to Date", labelled, plus a paragraph defining them.
  - Units KPI grid; team breakdown table (org scope); reporting-unit breakdown table; monthly plan-vs-actual table; prior-year comparison block (only if prior-year data exists).
  - All money via `App\Support\Money::format()` (₱ for PHP).
- **The existing `/performance` screen and all its Livewire components are untouched.**

---

## 13. Workbook mapping — `MCEB BRANCH CORPORATE BUDGET FOR 2026 MONITORING.xlsx`

> The XLSX was **not parsed** (spec §11). This is the mapping **specification and template**; the italicised cells must be filled and confirmed by Mike before any production CSV is built.

### 13.1 Team codes (derivable from sheet names — **confirm**)

Sheets present: `Summary`, `Budget`, `CEC Team`, `CBE Team`, `CEN Team`, `CNE Team`, `CSE Team`, `CEW Team`, `MCE Team`, `MCW Team`, `MNC Team`, `MNE Team`, `Sheet1`.

| Workbook sheet | Proposed `teams.code` | Must map to existing `teams.id` | `teams.name` |
|---|---|---|---|
| CEC Team | `CEC` | _confirm_ | _confirm_ |
| CBE Team | `CBE` | _confirm_ | _confirm_ |
| CEN Team | `CEN` | _confirm_ | _confirm_ |
| CNE Team | `CNE` | _confirm_ | _confirm_ |
| CSE Team | `CSE` | _confirm_ | _confirm_ |
| CEW Team | `CEW` | _confirm_ | _confirm_ |
| MCE Team | `MCE` | _confirm_ | _confirm_ |
| MCW Team | `MCW` | _confirm_ | _confirm_ |
| MNC Team | `MNC` | _confirm_ | _confirm_ |
| MNE Team | `MNE` | _confirm_ | _confirm_ |

**Action required:** the 10 existing `teams` rows currently need a `code` value set (one-time, additive, non-destructive `UPDATE` by Mike or a tiny data migration he approves) that exactly matches the codes above. The importer will **not** guess.

### 13.2 Reporting units (~44 branches/accounts) — **entirely to be supplied by Mike**

For **each** reporting unit, the following must be provided (no seed mapping is shipped):

| Field | Source | Notes |
|---|---|---|
| `team_id` | owning team sheet | one team per unit |
| `code` | **canonical code chosen by Mike** | stable identity; e.g. `EMALL`, `METRO_AYALA`, … — *not* the display string |
| `name` | Budget-sheet display label | human label only |
| `status` | active unless Mike says otherwise | |
| `sort_order` | optional, from sheet row order | |

**Known display-name discrepancies to reconcile to ONE code (Mike to confirm):**

| Budget sheet label | Actual sheet label | One canonical `code` | Decision |
|---|---|---|---|
| `E MALL` | `E-MALL` | _e.g._ `EMALL` | _confirm_ |
| `METRO AYALA CEBU` | `METRO AYALA` | _e.g._ `METRO_AYALA` | _confirm_ |
| _…any other pair found in the 44…_ | | | _confirm_ |

Until Mike supplies the full list, `reporting_units` stays empty and every import is rejected with `unknown reporting_unit_code` — which is the intended fail-closed behaviour.

### 13.3 Fiscal-month mapping (fixed)

| Workbook column / month | `period_month` (fiscal ordinal) |
|---|---|
| December 2025 | 1 |
| January 2026 | 2 |
| February 2026 | 3 |
| March 2026 | 4 |
| April 2026 | 5 |
| May 2026 | 6 |
| June 2026 | 7 |
| July 2026 | 8 |
| August 2026 | 9 |
| September 2026 | 10 |
| October 2026 | 11 |
| November 2026 | 12 |

### 13.4 Cell layout — **to be documented per sheet by Mike / a later parsing pass**

For the `Budget` sheet and each `<CODE> Team` sheet:

| Item | To capture |
|---|---|
| Plan Units cells | _sheet, row(s) per unit, 12 month columns_ |
| Plan Revenue (Peso) cells | _sheet, row(s), 12 month columns_ |
| Actual Units cells | _sheet, row(s), 12 month columns_ |
| Actual Revenue (Peso) cells | _sheet, row(s), 12 month columns_ |
| Blank vs zero convention | _does a blank month mean "0" or "not yet reported"? → import as blank (→ null), never 0, unless Mike states a month is a true zero_ |
| Total / subtotal / formula rows | **MUST be excluded** from detail import — list every "Total", "Grand Total", "TEAM TOTAL", per-team summary and formula row so the CSV builder skips them |
| Summary-sheet "YTD Percentage" | **informational only — do NOT import**; our two metrics are recomputed from detail lines |

### 13.5 Reconciliation totals to verify after import

- `Σ(reporting-unit plan revenue) per team` = that team's Budget-sheet team total.
- `Σ(team plan revenue)` = Summary-sheet organisation total.
- Same three identities for Units, and independently for Actuals.
- `Σ(phased Dec…Nov plan revenue)` = full-FY target per unit and per team.

### 13.6 Ambiguities flagged for Mike's confirmation

1. The 10 `teams.code` values (§13.1) and the one-time `teams` code backfill.
2. The full list of ~44 reporting units with canonical `code`s (§13.2).
3. Every Budget-vs-Actual name discrepancy and its single canonical code (§13.2 — `E MALL`/`E-MALL`, `METRO AYALA CEBU`/`METRO AYALA`, plus any others).
4. Blank-vs-zero convention per sheet (§13.4).
5. Exact total/formula rows to exclude (§13.4).
6. Whether any team budgets are held at team level (no branch phasing) — those become team-level plan lines (`reporting_unit_code` blank); actuals always need a unit.
7. `Sheet1` — assumed scratch/irrelevant; confirm it carries no authoritative figure.

---

## 14. Security / regression review

| Check | Result |
|---|---|
| No `Organization` pollution | ✅ `reporting_units` is a separate table, `belongsTo(Team)`, never referenced by CRM duplicate detection or Cost-to-Serve. |
| No fabricated `Opportunity` rows | ✅ importer writes only `performance_*_lines`; `assertDatabaseCount('opportunities', 0)` in service + import tests. |
| No autonomous CRM writes | ✅ import is a manual CLI action; the AI tool is strictly read-only. |
| Existing CRM sales-pipeline performance unchanged | ✅ `PerformanceService`, `Target`, `TargetService`, `Opportunity` untouched; `/performance` renders (regression test). |
| Existing Target architecture unchanged | ✅ no migration on `targets`; no `Target` model change. |
| Market Intelligence unchanged | ✅ no MI file touched; `V2FreezeInvariantsTest` updated only to add `get_fiscal_performance` to the forbidden-for-MI list. |
| Cost-to-Serve isolation | ✅ no Cost-to-Serve file touched; new tool not registered with the CostToServe agent; nothing exposes CtS data. |
| Team Head cannot see another team's operational data | ✅ `PerformanceAuthorizer::authorizeTeam` → 403; controller + tool tests. |
| Team Member scope | ✅ read-only, own team only; no org view. |
| Imports restricted to Manager/admin authority | ✅ CLI-only; `--as` must resolve to `isManager()`; documented as an operator action. |
| No CSV-supplied formula executed | ✅ no cell text persisted or evaluated; formula/injection cells fail numeric/code validation and are rejected. |
| No unsafe mass assignment | ✅ `ReportingUnit` `$fillable` excludes `team_id`; line models `$guarded=['id']` and are only written by the service from resolved ids. |
| No secrets / credentials logged | ✅ importer logs nothing; `performance_imports.summary` holds only counts; `source` is a filename. |
| Imported source text cannot influence AI / tool instructions | ✅ the tool returns numbers + a fixed `note` string; `source`/`imported_at` are metadata, never fed to the model as instructions; no free-text from the workbook reaches a prompt. |
| Full V1/V2 regression | ✅ **1143 passed, 0 failures** (was 1079). Pint passes. No existing test removed or weakened; the 2 edited test files gained assertions only. |

---

## 15. Recommended production migration / import sequence (LATER — not done here)

1. Mike reviews this report and confirms §13.6.
2. On a branch, Mike (or an approved tiny data migration) sets `teams.code` for the 10 teams — additive, non-destructive.
3. Commit this feature; tag; push; deploy code + run the 4 new migrations (all `CREATE TABLE`, no locking risk on existing tables). Backup first per standard policy.
4. Seed `reporting_units` from Mike's confirmed list (approved seeder or one-time CLI, on the server).
5. Build the normalised **plan** CSV from the workbook's Budget sheet (excluding total/formula rows). Run `php artisan performance:import-plan plan.csv --dry-run --as=<manager-email>`; review; then without `--dry-run`.
6. Run §13.5 reconciliation queries; compare against the workbook totals.
7. Build the **actuals** CSV (only months actually reported). `performance:import-actuals actuals.csv --dry-run --as=…`; review; import.
8. Re-run reconciliation; spot-check `/performance/fiscal` against the Summary sheet, remembering the two attainment metrics are **recomputed**, not copied.
9. Actuals are re-imported (idempotent) each month as new figures close.

---

## 16. Risks / unresolved issues

- **`teams.code` is currently unpopulated.** Every import fails until step 15.2 is done. Intentional fail-closed, but it is a required manual precondition.
- **Reporting-unit list is not in the repo.** ~44 units + canonical codes + name-discrepancy decisions are entirely pending Mike (§13.6). No seed data was guessed.
- **Workbook cell geometry not captured.** §13.4 needs either Mike's documentation or a separate, explicitly-approved parsing pass before a CSV can be built. The importer contract (normalised CSV) is fixed regardless.
- **`Money` decimal precision** — `decimal(16,2)`. Adequate for PHP branch budgets; revisit only if a figure exceeds ₱99,999,999,999,999.99.
- **Prior-year (FY2025) comparison** shows only if FY2025 plan/actual lines are imported; otherwise silently omitted (by design — never fabricated).
- **Timezone** — `FiscalYear` boundaries use `Carbon::create(...)` in the app timezone; `as_of` is a date, not a datetime. Consistent with the deterministic-date rule; no clock read where a date is supplied.
- Not committed / tagged / pushed / deployed; no production data imported — per spec §14.
