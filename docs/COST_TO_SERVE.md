# Cost-to-Serve Intelligence Agent (Phase 12)

This document is the authoritative reference for the Cost-to-Serve
capability introduced in Phase 12. If this document and the code ever
disagree, the code is a bug against this document, not the other way
around.

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
   Team (optional filter, Manager/Team-Head scoped)
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
  the LLM). Reuses `App\Http\Controllers\Concerns\ScopesCrmQueries`
  for authorization scoping — the identical Manager-unrestricted/
  Team-Head-own-team rule every other CRM query in this application
  uses, not a second hand-rolled rule.
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

Cost-to-Serve is Manager/Team-Head-only — commercial economics, never
exposed to a Team Member. `AgentIdentifier::CostToServe->isAvailableTo()`
is the single source of truth for this eligibility rule, consulted by:

- The assistant's agent dropdown (`AssistantController::show()`) —
  never offered to an ineligible user.
- `SendAssistantMessageRequest`'s validation — rejects an explicit
  `agent=cost_to_serve` selection server-side regardless of what the
  client's UI actually offered.
- `AgentRouter`'s auto-routing fallback (`AssistantController::
  sendMessage()`) — the router itself stays a pure, actor-unaware topic
  classifier (STEP 18 "routing is not security", unchanged); an
  ineligible actor's auto-routed request falls back to the Sales
  Intelligence agent instead.
- `CostToServeController` (the dedicated page) — `403` for anyone who
  isn't a Manager or Team Head.
- `AccountEconomicsService::scopeOrganizations()` — the actual data
  boundary every tool and the page re-derive on every call, never
  trusting the eligibility checks above alone.

A Team Head sees only their own team's organizations, at every layer
(the page, every tool, and organization-name resolution — an
out-of-scope organization is reported identically to "not found",
never confirming it exists).

## UI (STEP 21-23)

A dedicated page at `/cost-to-serve` (linked from the sidebar's
Performance group, Manager/Team-Head only) — summary KPIs first,
detail (top accounts, accounts to review) below, per "progressive
disclosure." Uses the existing Phase 11A design system and components
(`x-performance.kpi`, `flux:table`, `flux:callout`) unchanged — no new
UI theme. A persistent callout states the data-gap plainly on every
visit, not just when an AI question happens to surface it.

## Audit (STEP 26)

No new audit mechanism was built. Every Cost-to-Serve agent
interaction is already captured by the existing `AgentInteraction`
audit trail (Phase 7/9, unmodified) — user, timestamp, the message,
which tools were called with which arguments, status, and token usage
— exactly like the other three agents. Reused, not duplicated.

## Testing

56 new tests: `AccountEconomicsServiceTest` (calculation correctness,
zero-unit/zero-revenue/zero-deal edge cases, currency isolation,
authorization), `CostToServeToolsTest` (all 5 tools), `MetricChangeTest`
(zero-denominator handling), `CostToServeAgentAccessTest` (dropdown
filtering, explicit-selection rejection, auto-routing fallback),
`CostToServePromptInjectionTest` (injected organization notes, crafted
cross-team arguments, no SQL/write tool exists), `CostToServeControllerTest`
(page authorization and scoping), plus `AgentRegistryTest` extended for
the fourth agent's tool matrix. Full regression suite: 599 passed, 0
failed.

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
