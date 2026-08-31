# Target & Performance Calculations

This document is the authoritative definition of every target/performance
term and formula used in the application (Phase 4). The single
implementation of all of it is `App\Services\PerformanceService` —
no other file may recompute these formulas. If this document and the code
ever disagree, the code in `PerformanceService` is a bug against this
document, not the other way around.

The LLM is never involved in any of these calculations, now or in any
future phase. It may eventually *explain* a number that this service
already produced, but it never produces the number itself.

## Terminology

| Term | Definition |
|---|---|
| **Target** | The sales amount that must be achieved during a defined period. |
| **Actual** | The amount of recognized sales during that period — see "Actual sales definition" below. |
| **Achievement** | `Actual / Target × 100`. Undefined (not 0%) when there is no target, or the target is exactly zero. |
| **Gap** | `Target − Actual`. Negative when actual exceeds target (overachievement) — never hidden or clamped. |
| **Remaining Target** | `max(Target − Actual, 0)`. Never negative. |
| **Pipeline** | The sum of value across every *open* (not Closed Won, not Closed Lost) opportunity in scope. No probability weighting. |
| **Pipeline Coverage** | `Open Pipeline / Remaining Target`. Undefined once the target is already met (remaining target is 0) — never a literal infinity. |
| **Run Rate** | `Actual / Elapsed Days` — the average daily pace achieved so far. |
| **Required Run Rate** | `Remaining Target / Remaining Days` — the average daily pace still needed to hit the target by period end. |

## Actual sales definition

**Actual sales = the sum of `value` across every `Opportunity` in scope
whose `stage` is `ClosedWon` and whose `closed_at` falls within the
target period (inclusive of both boundary days).**

Explicitly excluded from actual, regardless of value or probability:
- Any open stage (`Qualification`, `Proposal`, `Negotiation`).
- `ClosedLost`.
- A `ClosedWon` opportunity whose `closed_at` falls outside the period.

`closed_at` — not `expected_close_date` — is the relevant date. This
field was added in this phase specifically because `expected_close_date`
is set at creation and is not reliably updated when a deal actually
closes; `App\Services\OpportunityService` sets `closed_at` automatically
the moment a stage transitions into a closed state (or on creation, if
created directly into one), and clears it if reopened. It can also be
set explicitly to backdate a historical/imported deal.

## Pipeline definition

**Open pipeline = the sum of `value` across every `Opportunity` in scope
whose `stage` is not `ClosedWon` and not `ClosedLost`.**

No date constraint is applied — an opportunity counts toward pipeline
regardless of its `expected_close_date`. No probability weighting is
applied. This is a deliberately simple, literal reading for V1; a future
phase could add "pipeline expected to close within the target period" as
a stricter variant without changing this document's core definition.

## Currency

A target has exactly one `currency`. Actual and pipeline are only summed
from opportunities whose `currency` matches the target's (or, for a
period-based calculation with no target record, the application default
`config('app.currency')` — `PHP` — is assumed). There
is no currency conversion in this phase — an opportunity in a different
currency, or with no currency set, is simply excluded from that
calculation rather than being incorrectly added to the total. This is a
known, documented limitation (see STEP 25); introducing FX conversion is
future scope.

## Scope (who/what an opportunity counts toward)

| Target type | Opportunities counted |
|---|---|
| Individual | `owner_id` = the target's owner |
| Team | `team_id` = the target's team |
| Manager (organisation) | every opportunity, org-wide, unscoped |

## Time-based metrics

Given a period's `period_start`/`period_end` (inclusive dates) and "now"
(the application's configured timezone):

- `total_days = period_start.diffInDays(period_end) + 1`
- **Future period** (`now < period_start`): `elapsed_days = 0`,
  `remaining_days = total_days`. Run rate is `null` ("not started yet",
  never a misleading 0/day).
- **Completed period** (`now > period_end`): `elapsed_days = total_days`,
  `remaining_days = 0`. Required run rate is `null` ("not applicable" —
  never a misleading 0, which could otherwise read as "nothing more was
  needed").
- **Current period** (`period_start <= now <= period_end`):
  `elapsed_days = period_start.diffInDays(now) + 1`,
  `remaining_days = total_days − elapsed_days`.

Required run rate is `0` whenever `remaining_target <= 0` (target already
met), regardless of period state — there is genuinely nothing more
required. It is `null` (not `0`, not a number) whenever the target is not
yet met and there is no time left to compute a rate against (a completed
period, or `remaining_days = 0` on the period's last day).

## Duplicate active targets

STEP 6 requires preventing accidental double-counting. An active target
is uniquely identified by `(target_type, owner_id or team_id,
period_start, period_end)` — `App\Services\TargetService` checks this
before every insert/update, and the database additionally enforces it
via two partial unique indexes (`targets_unique_active_owner`,
`targets_unique_active_team`) scoped to `status = 'active'`, so it can
never be violated even by a race condition or a future code path that
bypasses the service. Deactivating a target (`status = 'inactive'`)
frees up that combination for a new active target to be created.
