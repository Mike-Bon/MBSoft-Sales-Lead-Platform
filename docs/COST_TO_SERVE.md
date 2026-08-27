# Cost-to-Serve Intelligence Agent (Phase 12 / Phase 12A)

This document is the authoritative reference for the Cost-to-Serve
capability introduced in Phase 12. If this document and the code ever
disagree, the code is a bug against this document, not the other way
around.

**Phase 12A** (access model + live feature switch) revised the
authorization rule and added a persisted on/off toggle. Where the two
phases differ, Phase 12A wins — the ["Phase 12A — access model and
feature switch"](#phase-12a--access-model-and-feature-switch) section
below is the current, authoritative statement of who can reach this
feature, and the "Authorization (STEP 19/20)" and "UI (STEP 21-23)"
sections have been rewritten to match it.

## Why this is a revenue/engagement agent, not a true Cost-to-Serve agent

Before any code was written, the repository and schema were inspected
against the full Phase 12 specification. The finding, presented to and
confirmed with the user before implementation began:

**This application's database contains zero cost data of any kind.**
No transportation, delivery, pickup, manpower, handling, warehousing,
packaging, failed-delivery, return, reattempt, remote-area, COD, or
platform/technology cost exists anywhere in the schema. There is also
no shipment/unit-volume concept, no product/service catalog, and no
branch/route data. This is a sales-pipeline CRM (organizations →
leads → opportunities → activities/communications → targets), not an
operations/fulfillment/logistics system.

### Data availability matrix

| Data component | Status | Source |
|---|---|---|
| Customer/account identity | AVAILABLE | `organizations` |
| Revenue | AVAILABLE — narrow | `opportunities.value` where `stage = closed_won`, one-time deal value only |
| Units / shipment / transaction volume | NOT AVAILABLE | no such concept exists |
| Product / service catalog or type | NOT AVAILABLE | `opportunities.name` is free text |
| Transportation / delivery / pickup cost | NOT AVAILABLE | |
| Manpower cost | NOT AVAILABLE | |
| Handling / warehousing / packaging cost | NOT AVAILABLE | |
| Failed-delivery / return / reattempt cost | NOT AVAILABLE | no delivery concept exists |
| Remote-area cost | NOT AVAILABLE | |
| COD-related cost | NOT AVAILABLE | no payment data at all |
| Technology/platform cost | NOT AVAILABLE | |
| Branch | NOT AVAILABLE | `teams` is an internal sales-org grouping, not a physical branch |
| Route / area | NOT AVAILABLE | |
| Sales engagement volume | PARTIALLY AVAILABLE | `activities` + `communications` counts — an effort signal, not a cost |
| Period-over-period trending | AVAILABLE | `opportunities.closed_at`, `activities.occurred_at`, `communications.created_at` |

**Decision (confirmed with the user):** build the agent now, scoped
honestly to what's real — revenue concentration and sales-engagement
patterns — rather than inventing a cost model or waiting on a separate
cost-data-capture project. Every place the spec asked for a
cost/contribution/cost-to-serve-ratio/true-per-unit-ARPU figure,
this implementation says plainly that the data doesn't exist, rather
than approximating it.

## Cost model

There is no cost model. `App\Services\CostToServe\AccountEconomicsService`
computes exactly two categories of figure, both from real, existing
data:

1. **Revenue** — the sum of `opportunities.value` where
   `stage = closed_won` and `closed_at` falls within the period,
   filtered to one explicit currency (never summing mixed currencies —
   mirrors `PerformanceService::actualSales()`'s own convention
   exactly).
2. **Sales engagement** — the count of `activities` +
   `communications` records tied to the organization within the
   period. This is effort, never cost.

### Formulas actually implemented

| Metric | Formula | Notes |
|---|---|---|
| Revenue | `SUM(opportunities.value)` where Closed Won, in period, one currency | |
| Closed deals count | `COUNT(opportunities)` where Closed Won, in period | |
| Average revenue per closed deal | `revenue ÷ closed_deals_count` | The **approved substitute for classic ARPU** (confirmed with the user) — always labeled "average revenue per closed deal", never bare "ARPU". Null when there are zero closed deals, never a division-by-zero artifact. |
| Engagement count | `activity_count + communication_count` | |
| Period-over-period change | See `App\Support\CostToServe\MetricChange` | Handles a zero/near-zero previous value explicitly (`new`/`unchanged` states), never Infinity/NaN or a misleading percentage |

**Not implemented, because the data does not exist**: applicable
cost, cost per unit, cost-to-serve ratio, contribution, contribution
per unit, true per-unit ARPU, cost growth, contribution growth.

## Cost hierarchy actually supported

```
Customer / Account (Organization)
      ↓
   Team (optional filter; Manager-only since Phase 12A)
      ↓
  Time period
```

Product/service, shipment/transaction, branch, and route levels are
not implemented — none of that data exists (see the matrix above).

## Exception detection (STEP 11)

Three deterministic, config-driven rules
(`config('services.cost_to_serve')`), all revenue/engagement
patterns — never a cost claim:

1. **Revenue decline** — current-period revenue is at least
   `revenue_decline_threshold_percent` (default 20%) below the
   previous period's.
2. **Rising engagement, flat/declining revenue** — engagement rose by
   at least `engagement_growth_threshold_percent` (default 50%) while
   revenue did not increase.
3. **Zero revenue, meaningful engagement** — zero closed revenue in
   the period with at least `zero_revenue_engagement_threshold`
   (default 5) engagement touches recorded.

Every flagged account names exactly which rule(s) it tripped and the
configured threshold used — never a bare "this account looks bad."
Terminology is neutral throughout ("accounts to review", never "good"/
"bad customer").

## Services and tools

- `App\Services\CostToServe\AccountEconomicsService` — the one place
  every figure is calculated (STEP 29: database aggregation via
  Eloquent `groupBy`/`selectRaw`, never raw transaction rows handed to
  the LLM). Every public method begins with `assertAccess()` (Phase
  12A — Manager-only + global switch, see "Authorization" above); the
  underlying query still reuses
  `App\Http\Controllers\Concerns\ScopesCrmQueries` (a Manager is
  unrestricted there, so the net result for the one role that still
  has access is unchanged), not a second hand-rolled query.
- Five read-only `AgentTool`s (`app/Services/Ai/Tools/`):
  `get_customer_revenue_summary`, `get_customer_engagement_summary`,
  `get_revenue_concentration`, `compare_account_period`,
  `identify_revenue_exceptions`. Every result carries a `source` field
  (STEP 14) and, where relevant, a `data_gap` field explaining what a
  true cost figure would additionally require (STEP 4/15).
- `App\Enums\AgentIdentifier::CostToServe` — the fourth agent, a
  standalone instance of the same generic `Agent` engine from Phase 7,
  registered in `AppServiceProvider` exactly like the other three. It
  never joins `ManagementReviewOrchestrator` and there is no
  orchestrator/swarm change — Phase 12 adds one more agent to the
  existing closed set, nothing structural.
- `App\Services\Ai\Prompts\CostToServeAgentPrompt` — includes a
  "KNOWN DATA GAP" section stated up front, so the model never has to
  discover through trial and error that no cost tool exists, plus the
  shared `AgentPromptRules` text every agent includes verbatim.

## Authorization (STEP 19/20)

> **Phase 12A revision.** The original Phase 12 rule was
> "Manager/Team-Head-only". Phase 12A narrowed it to **Manager-only**
> and added a **global feature switch** on top. Both conditions must
> hold for access. See ["Phase 12A — access model and feature
> switch"](#phase-12a--access-model-and-feature-switch) for the
> rationale and the full matrix; this section describes how that
> single rule is enforced at every layer.

Cost-to-Serve is Manager-only commercial economics, and only while the
global switch is on. `App\Services\CostToServe\CostToServeAccessService`
is the single source of truth:

- `isEnabled()` — global feature status (is it on at all?). Reads the
  `cost_to_serve.enabled` row of the `settings` table; **defaults to
  `true`** when no row exists, so a fresh install needs no manual
  activation.
- `isRoleAuthorized(User)` — role authorization only, never the switch.
  `true` for a Manager, `false` for everyone else, unconditionally.
- `canAccess(User)` — `isRoleAuthorized() && isEnabled()`. The check
  every *feature-access* enforcement point calls.

`AgentIdentifier::CostToServe->isAvailableTo()` now delegates entirely
to `canAccess()`, so the eligibility rule can never drift from the
policy. It is consulted by:

- The assistant's agent dropdown (`AssistantController::show()`) —
  never offered to an ineligible user, and hidden from a Manager while
  the switch is off.
- `SendAssistantMessageRequest`'s validation — rejects an explicit
  `agent=cost_to_serve` selection server-side. The failure message is
  deliberately generic ("That assistant is not currently available.")
  so it never reveals *why* (role vs. switch) to someone who isn't
  role-authorized anyway.
- `AgentRouter`'s auto-routing fallback (`AssistantController::
  sendMessage()`) — the router itself stays a pure, actor-unaware topic
  classifier (STEP 18 "routing is not security", unchanged); an
  ineligible actor's (or an off-switch) auto-routed request falls back
  to the Sales Intelligence agent instead.
- `CostToServeController::index()` (the dedicated analysis page) —
  `403` for anyone who isn't a Manager; a `cost-to-serve.disabled`
  notice (HTTP 200, no figures) for a Manager while the switch is off.
- `AccountEconomicsService::assertAccess()` — the actual data boundary,
  called at the top of **every** public method (`resolveOrganization`,
  `scopeOrganizations`, every snapshot/summary method). It re-derives
  the full `canAccess()` decision from the actor on every call, never
  trusting the checks above. Order matters: role first (a Team Head
  always gets the same generic `AuthorizationException`, regardless of
  the switch's state), then the switch (a Manager sees a message that
  names the switch and how to turn it back on).

Feature **administration** is checked separately from feature access
(STEP 4 — "feature access ≠ feature administration"):

- `CostToServeController::settings()` / `updateSettings()` and
  `CostToServeAccessService::enable()` / `disable()` require only
  `isManager()` — deliberately **not** `canAccess()` — so turning the
  feature off can never lock the Manager out of turning it back on.
- Every state change writes an `AuditLogger` entry
  (`cost_to_serve.enabled` / `cost_to_serve.disabled`) carrying the
  actor, the previous state, and the new state, on the dedicated
  `audit` log channel.

Team Head scoping note: because a Team Head now has no access at all,
the Phase 12 "a Team Head sees only their own team's organizations"
behavior is moot — every Team Head request is rejected at
`assertAccess()` before any organization lookup runs, so there is no
"not found" vs. "found but restricted" distinction left to leak from.
A Manager remains unrestricted and may still pass an optional
`team_id` filter to narrow the view to one team.

## UI (STEP 21-23)

Two dedicated pages, both linked from the sidebar's Performance group,
**Manager-only** (a Team Head and Team Member see neither link):

- **`/cost-to-serve`** (`cost-to-serve.index`) — the analysis page.
  Summary KPIs first, detail (top accounts, accounts to review) below,
  per "progressive disclosure." While the switch is off it renders the
  `cost-to-serve.disabled` notice instead of any figures, with a link
  to the settings page.
- **`/cost-to-serve/settings`** (`cost-to-serve.settings`) — the
  administrative toggle. A status indicator (Enabled / Disabled), a
  single confirm-dialog form that `POST`s `enabled=0|1` to
  `cost-to-serve.settings.update`, and a note that enabling the feature
  does **not** grant Team Heads access. Always reachable by a Manager,
  on or off.

The sidebar's "Cost-to-Serve" analysis link carries a small **"Off"
badge** whenever the switch is off; the "Cost-to-Serve Settings" link
sits directly beneath it and is always shown to a Manager.

All pages use the existing Phase 11A design system and components
(`x-performance.kpi`, `flux:table`, `flux:callout`, `flux:badge`)
unchanged — no new UI theme. A persistent callout on the analysis page
states the data-gap plainly on every visit, not just when an AI
question happens to surface it.

## Audit (STEP 26)

No new audit mechanism was built. Every Cost-to-Serve agent
interaction is already captured by the existing `AgentInteraction`
audit trail (Phase 7/9, unmodified) — user, timestamp, the message,
which tools were called with which arguments, status, and token usage
— exactly like the other three agents. Reused, not duplicated.

## Phase 12A — access model and feature switch

### What changed and why

Phase 12 shipped Cost-to-Serve as **Manager/Team-Head-only**. Phase
12A revised that to two independent conditions that must *both* hold:

| Condition | Owner | Default | Notes |
|---|---|---|---|
| **Role authorization** — the user's role | Fixed in code | Manager only | Narrowed from "Manager or Team Head". There is no per-Team-Head toggle, deliberately — commercial economics is management-level information (CLAUDE.md least-privilege). |
| **Global feature switch** — is the feature on at all | The Manager, live, from the UI | **ON** | Persisted in the new `settings` table (`cost_to_serve.enabled`). A fresh install with no row is treated as ON. |

Why a switch at all: the Manager needs to be able to turn the whole
capability off (and back on) without a code deploy — the only prior
precedent (`config('services.workflows.*.enabled')`) is env-driven and
needs a deployment to change, which cannot satisfy "the Manager toggles
this live, and it persists".

### The final policy (must not be widened)

**Manager**
- Cost-to-Serve is ON by default.
- The Manager can turn it ON or OFF, at any time, from
  `/cost-to-serve/settings` — this is *administration*, gated on
  `isManager()` alone, so turning it OFF never locks the Manager out
  of turning it back ON.
- While it is ON, the Manager can use the analysis page, the assistant
  agent, and every tool.
- While it is OFF, the Manager sees the settings page and the
  `cost-to-serve.disabled` notice, and nothing else — no figures, no
  agent, no tool results.

**Team Head**
- Cost-to-Serve is OFF for them — permanently, structurally, at every
  layer.
- They cannot enable it (the settings page and toggle endpoint return
  `403`; `CostToServeAccessService::enable()` throws).
- A Manager enabling the feature does **not** enable it for Team Heads
  — `canAccess()` for a Team Head is `false` regardless of the switch.
- They never see the sidebar links, the assistant dropdown entry, or
  any tool/page output; every service call throws the same generic
  `AuthorizationException` before any data lookup.

**Team Member** — never had access; unchanged.

### Mechanism

- **`settings` table** (`database/migrations/2026_08_31_080000_create_settings_table.php`)
  — a deliberately generic `key`/`value` store (not a
  `cost_to_serve_settings` table) so a future feature flag reuses it.
  RLS follows the codebase-wide default-deny pattern (`ENABLE` +
  `FORCE`, no policies; the application role has `BYPASSRLS`).
- **`App\Models\Setting`** — `getValue()` / `setValue()` are the only
  intended access path. Not itself an authorization boundary.
- **`App\Services\CostToServe\CostToServeAccessService`** — the single
  source of truth: `isEnabled()`, `isRoleAuthorized()`, `canAccess()`,
  and the audited `enable()` / `disable()`. No caching — a single
  indexed key lookup, and a cache would only add a staleness risk.
- Enforcement points: `AgentIdentifier::CostToServe->isAvailableTo()`,
  `SendAssistantMessageRequest`, `AgentRouter` fallback,
  `CostToServeController` (`index` / `settings` / `updateSettings`),
  and `AccountEconomicsService::assertAccess()` — see "Authorization
  (STEP 19/20)" above for exactly what each does.
- **Not** changed: `AgentRouter` stays a pure topic classifier;
  `AccountEconomicsService`'s calculations; the tool contracts; the
  `AgentInteraction` audit trail.

### Migration status

The `settings` table migration is **written and green in the test
suite** (SQLite in-memory) but has **not** been run against Supabase.
That is the one remaining deploy step for Phase 12A — see the gate
report.

## Testing

**Phase 12 (original):** 56 tests across `AccountEconomicsServiceTest`,
`CostToServeToolsTest`, `MetricChangeTest`, `CostToServeAgentAccessTest`,
`CostToServePromptInjectionTest`, `CostToServeControllerTest`, plus
`AgentRegistryTest` for the fourth agent's tool matrix.

**Phase 12A additions / revisions:**

- **`Tests\Feature\CostToServe\CostToServeAccessPolicyTest`** — new,
  the single exhaustive reference for the access policy (every other
  Cost-to-Serve test docblock points here): the
  `isEnabled` / `isRoleAuthorized` / `canAccess` matrix, "enabling
  never grants a Team Head access", persistence across resolutions,
  single-row invariant, audit entries, the full HTTP surface of
  `GET`/`POST /cost-to-serve/settings` (Manager / Team Head / Team
  Member / guest, `required` + `boolean` validation, CSRF via the
  `web` group, enable→disable→enable round trip), and sidebar
  visibility (both links Manager-only, "Off" badge present only while
  disabled).
- `CostToServeControllerTest`, `CostToServeAgentAccessTest`,
  `CostToServeToolsTest`, `CostToServePromptInjectionTest`,
  `AccountEconomicsServiceTest` — updated: Team Head cases flipped from
  "own-team scoped" to "denied", new "feature switch off" cases at
  every layer.

Full regression suite after Phase 12A: **629 passed, 0 failed**
(`php artisan test`); `./vendor/bin/pint --test` clean.

## Future compatibility (STEP 34)

This phase adds one more `AgentDefinition` to the existing registry —
nothing about the architecture is specific to Cost-to-Serve. A future
Business Development Intelligence Agent (explicitly **not** built this
phase) would follow the identical pattern: one more `AgentIdentifier`
case, one more `AgentDefinition`, its own narrowly-scoped tools and
service. No orchestrator or agent-swarm capability was added or is
needed for that.

## Known limitations

1. **No true cost-to-serve, contribution, or per-unit ARPU** — by
   design, given the data gap above. This is the central, disclosed
   limitation of this phase, not an oversight.
2. **Revenue recognition is one-time deal value only** — this
   application has no recurring/subscription revenue concept.
3. **Currency handling excludes, never mixes** — an organization with
   closed deals in multiple currencies will show revenue for only the
   selected currency at a time; the summary/top-accounts views default
   to `COST_TO_SERVE_DEFAULT_CURRENCY` (USD).
4. **No scheduled Cost-to-Serve workflow** — unlike Phases 6-8's three
   proactive review workflows, this phase does not add one; not asked
   for in the spec, and adding one would be scope beyond "build the
   agent."
5. **No real Anthropic credentials were available while building this
   phase** (same situation as every prior AI phase) — every automated
   test uses `FakeLlmProvider`. Live model behavior when actually
   explaining a data gap to a Manager was not verified.
6. **Phase 12A: the `settings` table migration has not been run
   against Supabase** — it passes in the test suite (SQLite in-memory)
   but the production/dev schema change is deferred to a deliberate
   deploy step. Until it runs, `Setting::getValue()` on the real
   database would error; `isEnabled()`'s `true` default only applies
   once the table exists and is simply empty.
7. **Phase 12A: no per-Team-Head granularity** — the switch is global.
   The design leaves room for a future, more granular rule without
   changing `CostToServeAccessService::canAccess()`'s signature or any
   caller, but none exists now: a Team Head is unconditionally denied.
