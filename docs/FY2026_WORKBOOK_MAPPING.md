# FY2026 Workbook Mapping & Import Preparation

**Mode:** finalisation + release preparation. No production change, no real import, no team-code backfill, no reporting-unit seed, no commit/tag/push/deploy.

## Finalisation status (Mike's approved decisions — FY2026)

| Decision | Status |
|---|---|
| Fractional weighted units — `target_units` / `actual_units` `decimal(16,2)`, **not rounded** | ✅ implemented + tested |
| Revenue `decimal(14,2)` (app money convention) | ✅ implemented |
| 3 normalization aliases (`E MALL`/`E-MALL`, `STO.NINO`/`STO NINO`, `SM J MALL`/`SM JMALL`) | ✅ **NORMALIZED_CONFIRMED** |
| 22 likely aliases (word-drop shortenings) | ✅ **ALIAS_CONFIRMED** |
| 4 previously-ambiguous (`PACEO ARCENAS`/`PASEO`, `SM CEBU`/`SM CITY`, `MEZ-II`/`MEPZ 2`, `GAISANO GRAND MALL`/`GAISANO BASAK`) | ✅ **ALIAS_CONFIRMED** — Mike confirmed each is one physical location |
| 12 quarantined zero cells (`CEC E MALL` Jan, `CBE ESCARIO` Jan–Nov) | ✅ **excluded** — treated as *no reported actual*, not *reported zero* |
| Row-level 2dp monetary quantisation (ledger FY plan revenue ₱46,179,159.31; raw Δ −₱0.175) | ✅ **accepted** |
| Canonical name = Budget label; code = `<TEAM>_<NORMALIZED_BUDGET_LABEL>` | ✅ |

**All 44 reporting units are RESOLVED.** No `AMBIGUOUS` / `NEEDS_CONFIRMATION` status remains. Original Budget and Actual labels are retained for provenance in every artifact.

### Row-level 2-decimal quantisation — ACCEPTED (Mike, FY2026)

Summing the **528 detail rows at the ledger's 2-decimal precision** gives an organisation FY plan revenue of **₱46,179,159.31** (the production/import ledger target), vs the workbook's raw floating-point detail sum of **₱46,179,159.485**.

- **Raw difference: −₱0.175.**
- **Displayed as currency (half-up, 2dp): −₱0.18.**

Cause: `performance_plan_lines.target_revenue` is `decimal(14,2)`, PHP is represented to centavos, and every branch/month line is **independently** quantised to two decimals — so 528 sub-centavo roundings accumulate. **This has been explicitly accepted.** No individual detail value was altered and **no reconciliation row was modified to force totals to agree**. December **actuals reconcile exactly** (₱2,148,505.52). Production performance reporting uses the **database ledger amount after import**.

> **Units decision (APPROVED):** units are weighted/fractional business units — **not rounded**. `performance_plan_lines.target_units` and `performance_actual_lines.actual_units` are now `decimal(16,2)` nullable; revenue is `decimal(14,2)`. The 4 FY2026 migrations are **uncommitted and never released** (all `??` in git, absent from production), so the **original `CREATE TABLE` migrations were edited in place** — no `ALTER` migration. The `fy2026_plan_DRAFT.csv` with true fractional units is the intended source; `fy2026_plan_DRAFT_units_rounded.csv` is now a discarded reference artifact. Fractional dry-run: **528 plan / 39 actual accepted, 0 rejected** (§11).

**Source:** `MCEB BRANCH CORPORATE BUDGET FOR 2026 MONITORING.xlsx` (`~/Documents/Budget 2026/`, 85 KB, modified 2026‑01‑14), parsed directly with `openpyxl` (values + formulas). Sheet `Sheet1` listed in the earlier brief is **not present** in this file; the workbook has 12 sheets: `Summary`, `Budget`, and 10 `<CODE> Team` sheets.

**Preparation artifacts generated locally** (scratchpad, not committed, not imported):

| File | Rows | Purpose |
|---|---|---|
| `fy2026_reporting_unit_mapping.csv` | 44 | canonical reporting-unit master + alias classification |
| `fy2026_plan_DRAFT.csv` | 528 | normalized PLAN detail — **fractional units (intended source)**, dry-run 528/0 |
| `fy2026_plan_DRAFT_units_rounded.csv` | 528 | discarded reference artifact — do not use |
| `fy2026_alias_review.csv` | 29 | all non-EXACT unit mappings for human sign-off |
| `fy2026_actuals_DRAFT.csv` | 39 | normalized ACTUAL detail (December only, revenue only) |
| `fy2026_actuals_quarantined_zero_cells.csv` | 12 | hard-coded `0` cells held back from import, for Mike's ruling |
| `fy2026_reconciliation_report.csv` | 295 | workbook total vs normalized-detail total, per check |

---

## 1. Workbook structure

### `Budget` sheet — the PLAN / phased budget (authoritative for targets)

- Title rows 1–2. Month header row **4**: merged 2-column blocks — `B4:C4=December`, `D4:E4=January`, … `X4:Y4=November` (12 months). Sub-header row **5**: `Units` (left col) / `Peso` (right col) for every month. Columns **Z/AA** hold the **FY total** (Units/Peso) and are referenced by `Summary`.
- Ten team blocks. Each: a **team header row** (col A = `"CEC TEAM"` …), then **branch detail rows** (col A = branch name, B…Y = 12 × (Units, Peso), all **hand-entered numeric values, no formulas**), then **one unlabelled subtotal row** (`=SUM(...)` for the first 4 teams, hard-typed values for the other 6 — a workbook inconsistency, immaterial to us because we import detail only).
- Block coordinates (header row → subtotal row): CEC 5→9, CBE 11→16, CEN 18→23, CNE 25→31, CSE 33→39, CEW 41→46, MCE 48→53, MCW 55→61, MNC 63→69, MNE 71→77.
- Row **79** `Grand Total`. Rows **80–81 hidden**: `80 = "2025 Actual Performance"` (org-level monthly FY2025 actual **revenue** only, cols C…Y), `81 = =B79/B80` ratio.
- No hidden columns. Merged cells only in header row 4.
- `period_month` (fiscal ordinal) = workbook month position directly: December → 1 … November → 12.
- Extraction: units col = `2 + (m-1)*2`, peso col = `3 + (m-1)*2`.

### `<CODE> Team` sheets — the ACTUALS (authoritative for operational actuals)

- Title rows 1–3 (`A3="Responsible TH"`, `B3=` the TH name). Month header row **5** and Units/Peso sub-header row **6** — identical column geometry to `Budget` (`B/C=Dec` … `X/Y=Nov`).
- Branch detail rows are the **odd rows 7, 9, 11, …** (one per branch, in the same order as the `Budget` block). Between each pair of branch rows is a **`Hit Rate (%)`** formula row (even rows 8, 10, …) — **exclude**.
- After the last branch: a **hidden team-total row** (`=SUM(C7,C9,C11,…)`, Peso columns only) — this is what `Summary` pulls. Then visible rows `<CODE> TOTAL`, `TEAM HIT RATE`, `(+/-)` — **all exclude**.
- Total-row position depends on branch count: 3 branches → row 13, 4 → 15, 5 → 17.
- Actuals are **entered values**; `Hit Rate`, totals and `(+/-)` are formulas that reference `Budget`.

### `Summary` sheet — reconciliation only, never a detail source

- `B6:K6` `2026 Budget` per team = `=Budget!AA{9,16,23,…}` → **full-FY** budget.
- Rows 8–19 = months Dec…Nov, each cell `='<CODE> Team'!{C,E,G,…}{totalrow}` (team monthly actual revenue). `L` = `SUM(B:K)` overall; `M` = `L / Budget!{col}80` (vs FY2025 monthly actual); `N` = `L / Budget!{col}79` (vs monthly budget).
- Row 20 `YTD Performance` = `=SUM(B8:B19)`. Row 21 `YTD Budget (+/-)` = `=B20-B6`. Row 22 **`YTD Percentage` = `=B20/B6`**.
- Rows 25–28: `MCEB OVERALL` budget `46,179,159.49`, `ACTUAL PERFORMANCE` `2,148,505.52`, `(+/-)`, `PERCENTAGE 4.65 %`.

---

## 2. Team mapping

Workbook team codes are unambiguous from the sheet names / `Summary` headers. The **production `teams.code` column is currently `NULL`** for all 10 rows (factory default; demo seeders use `T01…T10`, not these codes). The workbook carries **no CRM team id or name** — only the code and the "Responsible TH". So the workbook cannot be joined to production automatically: a human runs the read-only command below and matches each row.

### Verification command (read-only, NOT executed here)

```
php artisan performance:show-teams
```

Prints, per team: `team_id | current_code | team_name | team_head_id | team_head_name | team_head_email`. It writes nothing.

### Proposed workbook-code ↔ Team-Head mapping (operator verifies each against the command output)

| workbook code | workbook "Responsible TH" (spelling may differ from the CRM user record) |
|---|---|
| `CEC` | MARTIN MELGAR |
| `CBE` | NEISHEM PORRAS |
| `CEN` | LEZ BERYL SANDE |
| `CNE` | CHERYL BRIZO |
| `CSE` | LEAH MAE MOBE |
| `CEW` | MARIETTA MELGAR |
| `MCE` | MELISSA YRAT |
| `MCW` | REXEL RAY TENEJEROS |
| `MNC` | GINA ROSE MENDOZA |
| `MNE` | BRYLE JAY LIM |

**Exact `teams.id` → code cannot be stated from here** (no production read was performed). After the command output confirms which `team_id` each TH heads, the backfill is one human-confirmed `UPDATE teams SET code = ? WHERE id = ?` per team — matched by Team Head + team name, **never by row order**. `performance:seed-reporting-units` refuses to run until all 10 codes are present.

---

## 3. Reporting-unit master (44 units)

Canonical code convention chosen: **`<TEAMCODE>_<SLUG>`**, uppercase, non-alphanumeric → `_`, derived from the **`Budget`-sheet label** (the more complete of the two). Unique within team; stable if the display name changes.

| # | team | `reporting_unit_code` | `Budget` label | `<CODE> Team` label | classification |
|---|---|---|---|---|---|
| 1 | CEC | `CEC_TABOAN` | TABOAN | TABOAN | EXACT |
| 2 | CEC | `CEC_GAISANO_SOUTH` | GAISANO SOUTH | GAISANO SOUTH | EXACT |
| 3 | CEC | `CEC_E_MALL` | E MALL | E-MALL | NORMALIZATION_ONLY |
| 4 | CBE | `CBE_METRO_AYALA_CEBU` | METRO AYALA CEBU | METRO AYALA | LIKELY_ALIAS — confirm |
| 5 | CBE | `CBE_KASAMBAGAN` | KASAMBAGAN | KASAMBAGAN | EXACT |
| 6 | CBE | `CBE_GORORDO` | GORORDO | GORORDO | EXACT |
| 7 | CBE | `CBE_ESCARIO` | ESCARIO | ESCARIO | EXACT |
| 8 | CEN | `CEN_RAMOS` | RAMOS | RAMOS | EXACT |
| 9 | CEN | `CEN_PACEO_ARCENAS` | PACEO ARCENAS | PASEO | **AMBIGUOUS** — `PACEO` vs `PASEO` spelling; likely the same ("Paseo Arcenas"), confirm |
| 10 | CEN | `CEN_ONE_PAVILLION` | ONE PAVILLION | PAVILLION | LIKELY_ALIAS — confirm |
| 11 | CEN | `CEN_FUENTE_OSMENA` | FUENTE OSMENA | OSMENA | LIKELY_ALIAS — confirm |
| 12 | CNE | `CNE_JY_SQUARE_MALL` | JY SQUARE MALL | JY | LIKELY_ALIAS — confirm |
| 13 | CNE | `CNE_GAISANO_GRAND_MALL_TALAMBAN` | GAISANO GRAND MALL TALAMBAN | TALAMBAN | LIKELY_ALIAS — confirm |
| 14 | CNE | `CNE_GQS` | GQS | GQS | EXACT |
| 15 | CNE | `CNE_CENTRAL_BLOC_MALL` | CENTRAL BLOC MALL | CENTRAL BLOC | LIKELY_ALIAS — confirm |
| 16 | CNE | `CNE_CABANCALAN_ROAD` | CABANCALAN ROAD | CABANCALAN | LIKELY_ALIAS — confirm |
| 17 | CSE | `CSE_STO_NINO` | STO.NINO | STO NINO | NORMALIZATION_ONLY |
| 18 | CSE | `CSE_SM_CEBU` | SM CEBU | SM CITY | **AMBIGUOUS** — `SM CEBU` vs `SM CITY` (likely "SM City Cebu"), confirm |
| 19 | CSE | `CSE_ROBINSONS_GALLERIA_CEBU` | ROBINSONS GALLERIA CEBU | ROBINSON | LIKELY_ALIAS — confirm |
| 20 | CSE | `CSE_MABOLO` | MABOLO | MABOLO | EXACT |
| 21 | CSE | `CSE_COLONNADE` | COLONNADE | COLONNADE | EXACT |
| 22 | CEW | `CEW_SM_SEASIDE` | SM SEASIDE | SM SEASIDE | EXACT |
| 23 | CEW | `CEW_SHOPWISE` | SHOPWISE | SHOPWISE | EXACT |
| 24 | CEW | `CEW_GAISANO_TISA` | GAISANO TISA | GAISANO TISA | EXACT |
| 25 | CEW | `CEW_BULACAO` | BULACAO | BULACAO | EXACT |
| 26 | MCE | `MCE_MARIBAGO_BRANCH` | MARIBAGO BRANCH | MARIBAGO | LIKELY_ALIAS — confirm |
| 27 | MCE | `MCE_MACTAN_MARINA_MALL` | MACTAN MARINA MALL | MARINA MALL | LIKELY_ALIAS — confirm |
| 28 | MCE | `MCE_GAISANO_ISLAND_MALL` | GAISANO ISLAND MALL | ISLAND MALL | LIKELY_ALIAS — confirm |
| 29 | MCE | `MCE_CEBU_AIRPORT` | CEBU AIRPORT | AIRPORT | LIKELY_ALIAS — confirm |
| 30 | MCW | `MCW_MEZ_II` | MEZ-II | MEPZ 2 | **AMBIGUOUS** — `MEZ-II` vs `MEPZ 2` (likely "MEPZ II"), confirm |
| 31 | MCW | `MCW_LAPU_LAPU_MARKET_BRANCH` | LAPU LAPU MARKET BRANCH | LAPU-LAPU MARKET | LIKELY_ALIAS — confirm |
| 32 | MCW | `MCW_GMC_PAJO` | GMC PAJO | GMC PAJO | EXACT |
| 33 | MCW | `MCW_GAISANO_GRAND_MALL_CORDOVA` | GAISANO GRAND MALL CORDOVA | GAISANO CORDOVA | LIKELY_ALIAS — confirm |
| 34 | MCW | `MCW_GAISANO_GRAND_MALL` | GAISANO GRAND MALL | GAISANO BASAK | **AMBIGUOUS / HIGH RISK** — position-5 in both blocks, but `GAISANO GRAND MALL` vs `GAISANO BASAK` share only "GAISANO". Possible mislabel or relocated branch. **Confirm explicitly.** |
| 35 | MNC | `MNC_NORTH_ATRIUM_MANDAUE` | NORTH ATRIUM MANDAUE | NORTH ATRIUM | LIKELY_ALIAS — confirm |
| 36 | MNC | `MNC_MANDAUE_RECLAMATION` | MANDAUE RECLAMATION | RECLAMATION | LIKELY_ALIAS — confirm |
| 37 | MNC | `MNC_MANDAUE_PARKMALL` | MANDAUE PARKMALL | PARKMALL | LIKELY_ALIAS — confirm |
| 38 | MNC | `MNC_ARCADA_5` | ARCADA 5 | ARCADA | LIKELY_ALIAS — confirm |
| 39 | MNC | `MNC_AC_CORTEZ` | AC CORTEZ | AC CORTES | NORMALIZATION-ish — `Z` vs `S` spelling, confirm |
| 40 | MNE | `MNE_PACIFIC_MALL_MANDAUE` | PACIFIC MALL MANDAUE | PACIFIC MALL | LIKELY_ALIAS — confirm |
| 41 | MNE | `MNE_GAISANO_CAPITAL_CASUNTINGAN` | GAISANO CAPITAL CASUNTINGAN | CASUNTINGAN | LIKELY_ALIAS — confirm |
| 42 | MNE | `MNE_BASAK_MANDAUE` | BASAK MANDAUE | BASAK MANDAUE | EXACT |
| 43 | MNE | `MNE_BANILAD_MANDAUE` | BANILAD MANDAUE | BANILAD | LIKELY_ALIAS — confirm |
| 44 | MNE | `MNE_SM_J_MALL` | SM J MALL | SM JMALL | NORMALIZATION_ONLY |

Counts per team: CEC 3, CBE 4, CEN 4, CNE 5, CSE 5, CEW 4, MCE 4, MCW 5, MNC 5, MNE 5 = **44**.

### Classification summary — FINAL (all human-confirmed by Mike)

| `mapping_status` | count | meaning |
|---|---|---|
| `EXACT` | 15 | `Budget` and team-sheet labels identical |
| `NORMALIZED_CONFIRMED` | 3 | differ only by spaces/punctuation (`E MALL`/`E-MALL`, `STO.NINO`/`STO NINO`, `SM J MALL`/`SM JMALL`) — confirmed same unit |
| `ALIAS_CONFIRMED` | 26 | different wording (word-drop shortening, spelling, or — for the 4 once-ambiguous pairs — a materially different label); **Mike confirmed each is one physical/reporting location** |

The `<TEAM>_<NORMALIZED_BUDGET_LABEL>` code and the Budget label as `canonical_name` are the single business identity per unit; the differing team-sheet label is retained in the `aliases` field for provenance. The historical review tables (3A below) are kept as the record of how each mapping was reached.

### 3A. Alias review — how each non-EXACT mapping was resolved

All 29 non-EXACT pairs. **Every row is now `NORMALIZED_CONFIRMED` or `ALIAS_CONFIRMED`** (Mike, FY2026 finalisation). The `A/B/C` grouping below is the review-time classification, kept for audit.

**A. NORMALIZATION_ONLY** (differ only by spaces/punctuation — safe to normalise, still confirm)

| team | reporting_unit_code | budget_label | actual_label | reason | recommended | confidence |
|---|---|---|---|---|---|---|
| CEC | `CEC_E_MALL` | E MALL | E-MALL | space vs hyphen | same branch | high |
| CSE | `CSE_STO_NINO` | STO.NINO | STO NINO | dot vs space | same branch | high |
| MNE | `MNE_SM_J_MALL` | SM J MALL | SM JMALL | internal space | same branch | high |

**B. LIKELY_ALIAS_NEEDS_CONFIRMATION** (one label is a shortening of the other; same block position; totals reconcile)

| team | reporting_unit_code | budget_label | actual_label | reason | confidence |
|---|---|---|---|---|---|
| CBE | `CBE_METRO_AYALA_CEBU` | METRO AYALA CEBU | METRO AYALA | `CEBU` suffix dropped | high |
| CEN | `CEN_ONE_PAVILLION` | ONE PAVILLION | PAVILLION | `ONE ` prefix dropped | high |
| CEN | `CEN_FUENTE_OSMENA` | FUENTE OSMENA | OSMENA | `FUENTE ` prefix dropped | high |
| CNE | `CNE_JY_SQUARE_MALL` | JY SQUARE MALL | JY | shortened to `JY` | medium |
| CNE | `CNE_GAISANO_GRAND_MALL_TALAMBAN` | GAISANO GRAND MALL TALAMBAN | TALAMBAN | shortened to locality | medium |
| CNE | `CNE_CENTRAL_BLOC_MALL` | CENTRAL BLOC MALL | CENTRAL BLOC | `MALL` suffix dropped | high |
| CNE | `CNE_CABANCALAN_ROAD` | CABANCALAN ROAD | CABANCALAN | `ROAD` suffix dropped | high |
| CSE | `CSE_ROBINSONS_GALLERIA_CEBU` | ROBINSONS GALLERIA CEBU | ROBINSON | shortened + singular | medium |
| MCE | `MCE_MARIBAGO_BRANCH` | MARIBAGO BRANCH | MARIBAGO | `BRANCH` suffix dropped | high |
| MCE | `MCE_MACTAN_MARINA_MALL` | MACTAN MARINA MALL | MARINA MALL | `MACTAN ` prefix dropped | high |
| MCE | `MCE_GAISANO_ISLAND_MALL` | GAISANO ISLAND MALL | ISLAND MALL | `GAISANO ` prefix dropped | high |
| MCE | `MCE_CEBU_AIRPORT` | CEBU AIRPORT | AIRPORT | `CEBU ` prefix dropped | high |
| MCW | `MCW_LAPU_LAPU_MARKET_BRANCH` | LAPU LAPU MARKET BRANCH | LAPU-LAPU MARKET | hyphen + `BRANCH` dropped | high |
| MCW | `MCW_GAISANO_GRAND_MALL_CORDOVA` | GAISANO GRAND MALL CORDOVA | GAISANO CORDOVA | `GRAND MALL` dropped, locality kept | medium |
| MNC | `MNC_NORTH_ATRIUM_MANDAUE` | NORTH ATRIUM MANDAUE | NORTH ATRIUM | `MANDAUE` suffix dropped | high |
| MNC | `MNC_MANDAUE_RECLAMATION` | MANDAUE RECLAMATION | RECLAMATION | `MANDAUE ` prefix dropped | high |
| MNC | `MNC_MANDAUE_PARKMALL` | MANDAUE PARKMALL | PARKMALL | `MANDAUE ` prefix dropped | high |
| MNC | `MNC_ARCADA_5` | ARCADA 5 | ARCADA | trailing `5` dropped | medium |
| MNC | `MNC_AC_CORTEZ` | AC CORTEZ | AC CORTES | `Z` vs `S` spelling | medium |
| MNE | `MNE_PACIFIC_MALL_MANDAUE` | PACIFIC MALL MANDAUE | PACIFIC MALL | `MANDAUE` suffix dropped | high |
| MNE | `MNE_GAISANO_CAPITAL_CASUNTINGAN` | GAISANO CAPITAL CASUNTINGAN | CASUNTINGAN | `GAISANO CAPITAL ` prefix dropped | high |
| MNE | `MNE_BANILAD_MANDAUE` | BANILAD MANDAUE | BANILAD | `MANDAUE` suffix dropped | high |

**C. Previously AMBIGUOUS — now `ALIAS_CONFIRMED` by Mike** (each confirmed to be one physical/reporting location; aliases recorded for audit)

| team | reporting_unit_code | budget_label (canonical) | actual_label (alias) | Mike's confirmation |
|---|---|---|---|---|
| CEN | `CEN_PACEO_ARCENAS` | PACEO ARCENAS | PASEO | same reporting unit |
| CSE | `CSE_SM_CEBU` | SM CEBU | SM CITY | same reporting unit |
| MCW | `MCW_MEZ_II` | MEZ-II | MEPZ 2 | same reporting unit |
| MCW | `MCW_GAISANO_GRAND_MALL` | GAISANO GRAND MALL | GAISANO BASAK | same reporting unit |

---

## 4. Budget (PLAN) extraction contract

Normalized candidate row (matches `performance:import-plan` CSV contract exactly):

```
fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue
```

- `fiscal_year = 2026`, `currency = PHP` (fixed).
- One row per (reporting unit × fiscal month) = **44 × 12 = 528 rows**. Verified: **every unit has all 12 months; zero blank cells; zero zero-valued cells; no `#REF!`/error cells; branch rows carry no formulas.**
- Excluded: team header rows, the 10 subtotal rows, `Grand Total` (79), hidden `2025 Actual` (80) and ratio (81).
- Expected total plan revenue = **₱46,179,159.48** (matches `Summary` `MCEB OVERALL` 46,179,159.49, 1-centavo rounding).

### Fractional planned units — RESOLVED (option A)

`Budget` "Units" are **fractional** (e.g. `TABOAN` Dec = `278.4`, `E MALL` Dec = `79.75`, `SM J MALL` = `132.66…`) — weighted/blended figures, not counts. **479 of 528 plan rows carry a non-integer `target_units`.**

Applied: `target_units` / `actual_units` → `decimal(16,2)` nullable; model casts `decimal:2`; `PerformanceImportService` parses the units column with `parseDecimal` (0, 278, 278.4, 278.40, 0.25 accepted; negatives / text / `NaN` / `Infinity` / scientific / formulas rejected); `FiscalPerformanceService` treats units as `?float` throughout (full FY, YTD phased, YTD actual, variance, remaining, required-monthly) — no integer casts, no `ceil`. Migrations edited in place (never released). Fractional dry-run now passes 528/0 (§11).

---

## 5. Actuals extraction contract

Normalized candidate row (matches `performance:import-actuals` contract):

```
fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue
```
plus `currency = PHP`, `source = FY2026_WORKBOOK_<sheet>` (e.g. `FY2026_WORKBOOK_CEC_Team`).

**Findings:**

- **The only genuinely reported actuals are December (fiscal month 1), revenue only.** 39 of 44 branches have a December Peso value; the 5 `CNE` branches are blank for December (→ `Summary` shows CNE December = 0).
- **No `Units` actuals anywhere** — all 528 unit cells across all 10 team sheets are blank. Every actual row therefore has `actual_units` blank (→ stored `NULL`, which the importer allows).
- **No Jan–Nov actuals**, with two exceptions that are **hard-coded `0` cells, not reported zeros**:
  - `CEC_E_MALL` January (`E11 = 0`)
  - `CBE_ESCARIO` January–November (`E13:Y13 = 0`, all 11 months)
  These 12 cells are template artefacts (a single branch row filled with zeros while its neighbours are blank). They are **quarantined** in `fy2026_actuals_quarantined_zero_cells.csv` and **excluded** from `fy2026_actuals_DRAFT.csv`. Importing them would create false "₱0 revenue reported" actuals for Feb–Nov and distort `YTD Target Attainment` for CBE. **Mike to rule**: treat as blank (recommended) or as genuine zeros.
- **Partial rows:** every emitted actual row has revenue present and units blank — expected given there are no unit actuals. No row has units-without-revenue.
- Expected actual rows to import (December, non-zero) = **39**. Latest populated actual month **overall = December (ordinal 1)**; **every team's latest genuine actual month = December**. (The "latest month" for CBE/CEC would look like Nov/Jan if the quarantined zeros were counted — they should not be.)

---

## 6. Summary-sheet metric semantics

`Summary!` `YTD Percentage` (row 22) = **`YTD Performance (row 20) ÷ 2026 Budget (row 6)`** where row 6 = `Budget!AA*` = the **full-year** budget.

> The workbook's "YTD Percentage" is therefore **`YTD Actual Revenue ÷ Full-FY Budget Revenue`** — i.e. exactly the CRM's **`FY Attainment to Date`** metric.

The workbook does **not** compute a phased/elapsed-months attainment anywhere cumulatively (only per-month, in row `N` = month actual ÷ month budget). The CRM's second metric, **`YTD Target Attainment` = `YTD Actual ÷ Σ phased target through the reporting month`**, is genuinely new and has no workbook equivalent. Both are kept, separately named, in `FiscalPerformanceService` / the `/performance/fiscal` view — the workbook label is not reused.

Also available (not imported): `Budget!` row 80 (hidden) = **org-level monthly FY2025 actual revenue** (Dec `2,725,638.90` … Nov `2,277,118`). Not per-team, not per-branch — insufficient for a per-scope prior-year comparison, so `FiscalPerformanceService::priorYearIfAny()` will correctly return nothing for FY2026 unless FY2025 detail is separately loaded.

---

## 7. Reconciliation results

### 7.1 Extraction faithfulness — `fy2026_reconciliation_report.csv`, **297 checks, 0 mismatches**

Raw detail (workbook value, unrounded) vs workbook subtotal / grand-total / `Summary` rows:

| check | scope | result |
|---|---|---|
| PLAN detail Σ(units) per team per month = `Budget` subtotal row | 10 teams × 12 months | ✅ all match |
| PLAN detail Σ(revenue) per team per month = `Budget` subtotal row | 120 | ✅ all match |
| PLAN detail Σ(12 months) per team = `Budget!Z/AA` FY total | 10 + 10 | ✅ all match |
| PLAN detail Σ(all teams) per month = `Budget` `Grand Total` row 79 | 12 + 12 | ✅ all match |
| ACTUAL detail Σ(revenue) per team, December = `Summary!` row 8 | 10 | ✅ all match |
| ACTUAL detail Σ(all teams), December = `Summary!L8` | 1 | ✅ `₱2,148,505.52` exact |

Per-team FY plan revenue (raw): CEC 1,734,939.96 · CBE 4,176,018.95 · CEN 5,344,597.63 · CNE 8,444,849.47 · CSE 3,993,833.32 · CEW 3,813,581.32 · MCE 1,354,815.55 · MCW 3,526,424.65 · MNC 11,352,091.63 · MNE 2,438,007.00 · **raw total ₱46,179,159.485**.

### 7.2 Import precision — 2-decimal ledger vs workbook raw float

The final CSV stores every figure at the ledger's `decimal(_,2)` precision (half-up). Summing those:

| scope | 2dp ledger Σ | workbook raw float | raw Δ | Δ (half-up, 2dp) | class |
|---|---|---|---|---|---|
| PLAN org FY revenue | **₱46,179,159.31** | ₱46,179,159.485 | **−₱0.175** | **−₱0.18** | `QUANTISATION_2DP` — **ACCEPTED** |
| ACTUAL org December revenue | **₱2,148,505.52** | ₱2,148,505.52 | ₱0.000 | ₱0.00 | `EXACT` |
| PLAN org FY units | 277,715.81 | (weighted, no workbook grand total) | — | — | — |

The plan-revenue difference is the accumulated 2-decimal quantisation of 528 sub-centavo monthly Budget figures (e.g. `46543.10049999999`) — each line independently rounded to centavos for the `decimal(14,2)` column. **No detail row was altered; no reconciliation row was modified to force agreement.** Actuals reconcile exactly because the December workbook figures were already 2-decimal. **Mike has explicitly accepted the ₱46,179,159.31 ledger target.** Production performance reporting must use the post-import database amount.

No `FORMULA_DIFFERENCE` / `SOURCE_LABEL_MISMATCH` / `MISSING_DETAIL` / `DUPLICATE_DETAIL` / `UNEXPLAINED` was triggered. The one workbook oddity — 6 of 10 team subtotal rows hard-typed rather than `=SUM()` — does not affect detail extraction; their values still equal the detail sums.

---

## 8. Blank vs zero audit

### PLAN (`Budget`, 528 unit-months)

| | blank | explicit 0 |
|---|---|---|
| Units | 0 | 0 |
| Revenue (Peso) | 0 | 0 |

Fully populated, no ambiguity. (Blank-in-plan convention: **n/a — never occurs**.)

### ACTUAL (10 team sheets, 528 unit-months)

| | blank | explicit 0 |
|---|---|---|
| Units | 528 | 0 |
| Revenue (Peso) | 477 | 12 |

Revenue blanks by fiscal month: `Dec 5` · `Jan 42` · `Feb–Nov 43 each`. → Future months are **blank, not zero**. The 12 explicit zeros are entirely in `CEC_E_MALL` (Jan) and `CBE_ESCARIO` (Jan–Nov) — see §5. Not normalized; quarantined pending Mike's ruling.

---

## 9. Canonical code proposal

Convention: **`<TEAMCODE>_<SLUG>`** — uppercase; `[^A-Z0-9]+` → single `_`; trimmed; derived from the `Budget` label; unique within team; no spaces/punctuation (CSV-safe); stable across display-name changes; never a database id. Full list in §3 and `fy2026_reporting_unit_mapping.csv`. Examples: `CEC_TABOAN`, `CEC_E_MALL`, `CBE_METRO_AYALA_CEBU`, `MNC_MANDAUE_RECLAMATION`, `MCW_MEZ_II`.

---

## 10. Final artifacts (local only — NOT committed)

Generated by `scratchpad/extract.py` from the workbook. Contain branch-level revenue budget figures → **sensitive operational data, kept out of source control.** SHA-256 + row counts recorded in `fy2026_checksums.txt` and below so the committed pipeline is verifiable.

| file | rows | SHA-256 |
|---|---|---|
| `fy2026_plan.csv` | 528 | `234f1063…d1d1cc` |
| `fy2026_actuals.csv` | 39 | `9d7c63c6…8377567` |
| `fy2026_reporting_unit_mapping.csv` | 44 | `7cf64e5f…e280238` |
| `fy2026_reconciliation_report.csv` | 297 | `83a8d64d…d8bb391` |
| `fy2026_actuals_quarantined_zero_cells.csv` | 12 | `4532f12e…65331a4` |

`fy2026_plan.csv` / `fy2026_actuals.csv` columns match the `performance:import-*` contracts exactly. The reporting-unit master list is committed in code as `App\Support\Performance\ReportingUnitCatalog` (names + codes only, no figures) and applied by `performance:seed-reporting-units`.

---

## 11. Offline dry-run results

Finalisation harness (`scratchpad/dryrun_harness.php`): throwaway **SQLite `:memory:`** (same mechanism as the test suite — **no external connection, nothing in production touched**), 10 teams seeded *with* codes + a Team Head each, then `performance:seed-reporting-units`, then the real `PerformanceImportService` against the FINAL CSVs:

| step | result |
|---|---|
| `performance:show-teams` | read-only table of 10 teams + heads; nothing written |
| `performance:seed-reporting-units --dry-run` | "Would apply: 44 new"; `reporting_units` = 0 |
| `performance:seed-reporting-units` | "44 new"; `reporting_units` = 44 |
| re-run (idempotency) | "0 new, 0 updated, 44 unchanged"; still 44 |
| `fy2026_plan.csv` — dry-run | **528 accepted, 0 rejected** |
| `fy2026_actuals.csv` — dry-run | **39 accepted, 0 rejected** |
| real offline import → `performance_plan_lines` | 528 rows, 528 distinct business keys |
| real offline import → `performance_actual_lines` | 39 rows, `actual_units` NULL on all 39 |
| fractional `target_units` preserved | 479 / 528 rows non-integer |
| `CBE_ESCARIO` / `CEC_E_MALL` actual rows | 1 each (December only — 12 quarantined zeros excluded) |
| `opportunities` / `organizations` | 0 / 0 |
| org FY plan revenue | ₱46,179,159.31 (see §7.2 — accepted 2dp quantisation, raw Δ −₱0.175) |
| December org actual revenue | ₱2,148,505.52 (exact) |

**No artisan command was run against any real database.** The configured `.env` connection is the production Supabase pooler; the new tables are not deployed there; `teams.code` is unpopulated; `reporting_units` is empty. Even a `--dry-run` writes one `performance_imports` audit row, which is why every dry-run above was offline.

---

## 12. Master data required before import (NOT executed)

1. **`teams.code` backfill** — 10 rows. Run **`php artisan performance:show-teams`** (read-only), verify each team id/name + Team Head against the workbook "Responsible TH" (§2), then one `UPDATE teams SET code = ? WHERE id = ?` per team. Additive; no other column touched. NOT executed.
2. **`reporting_units` seed** — **`php artisan performance:seed-reporting-units`** (idempotent; `--dry-run` first). Uses `App\Support\Performance\ReportingUnitCatalog` (44 rows, committed in code). Fails closed if any team code is missing or the resolved count ≠ 44. NOT executed.
3. **Units-type** (§3A) — ✅ done in code (option A): migrations, casts, `parseDecimal`, `FiscalPerformanceService` all fractional-safe.
4. **Alias confirmations** (§3A) — ✅ all 29 non-EXACT mappings confirmed by Mike (`NORMALIZED_CONFIRMED` / `ALIAS_CONFIRMED`).
5. **Zero-cell ruling** (§5/§7) — ✅ Mike: the 12 cells are *no reported actual* (blank), **excluded** from `fy2026_actuals.csv`.
6. **Plan-revenue quantisation** (§7.2) — ✅ **ACCEPTED**: ledger target ₱46,179,159.31 (raw Δ −₱0.175 / displayed −₱0.18 vs the workbook raw float). No data altered.

---

## 13. Recommended production import order (later, each step human-confirmed)

1. ✅ Plan-revenue quantisation accepted (§7.2); feature committed (code only; CSVs stay local).
2. Land the FY2026 code (feature branch → review → tag → deploy). Back up the DB first.
3. Deploy + run the 4 FY2026 migrations — all `CREATE TABLE` + partial indexes on the new empty tables, no lock on any existing table.
4. `php artisan performance:show-teams` → verify → run the 10 confirmed `teams.code` `UPDATE`s.
5. `php artisan performance:seed-reporting-units --dry-run` → review → run for real (44 units).
6. Transfer `fy2026_plan.csv` (verify SHA-256 = `234f1063…`). `php artisan performance:import-plan fy2026_plan.csv --dry-run --as=<manager-email>` → expect **528 / 0**. Review → re-run without `--dry-run`.
7. Reconcile `performance_plan_lines` against the workbook (expect org FY revenue ₱46,179,159.31, the confirmed 2dp figure).
8. Transfer `fy2026_actuals.csv` (SHA-256 = `9d7c63c6…`). `performance:import-actuals ... --dry-run --as=…` → expect **39 / 0**. Review → import.
9. Reconcile actuals vs `Summary` (December = ₱2,148,505.52). Spot-check `/performance/fiscal` — only `FY Attainment to Date` should equal the workbook's "YTD Percentage".
10. Each subsequent month: regenerate `fy2026_actuals.csv` with the newly closed month(s) and re-run `performance:import-actuals` (idempotent upsert).

---

## 14. Human decisions recorded (Mike, FY2026 finalisation)

- **Fractional weighted units preserved** — `target_units` / `actual_units` `decimal(16,2)`, never rounded. Revenue `decimal(14,2)`.
- **3 normalization aliases** approved as the same reporting unit: `E MALL`=`E-MALL`, `STO.NINO`=`STO NINO`, `SM J MALL`=`SM JMALL`.
- **22 likely aliases** approved as the same reporting unit (word-drop shortenings — full list §3A.B).
- **4 previously-ambiguous aliases** approved as the same physical/reporting location: `PACEO ARCENAS`=`PASEO`, `SM CEBU`=`SM CITY`, `MEZ-II`=`MEPZ 2`, `GAISANO GRAND MALL`=`GAISANO BASAK`. Alias text retained for audit; one identity per pair.
- **12 suspicious zero cells** (`CEC E MALL` Jan, `CBE ESCARIO` Jan–Nov) treated as **no reported actual** (blank), **excluded** from the FY2026 actual import. Semantic rule preserved: blank = no row; verified explicit zero = a row with `0.00`.
- **Row-level 2-decimal monetary quantisation accepted.** Import ledger FY plan revenue target = **₱46,179,159.31**. Workbook raw float sum = **₱46,179,159.485**. Raw difference **−₱0.175** (displayed half-up **−₱0.18**), caused solely by independently quantising 528 branch/month lines to centavos for `decimal(14,2)`. No detail value altered; no reconciliation row modified. Production reporting uses the post-import database amount. `ACTUAL` December revenue = **₱2,148,505.52** exactly.

## 15. STOP

No production change. No real import. No commit / tag / push / deploy. Awaiting Mike's confirmations in §12.
