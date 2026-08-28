# Business Development Intelligence (Phase 13)

This document is the authoritative reference for the Business
Development capability introduced in Phase 13 — the **final
feature-development phase of V1**. If this document and the code ever
disagree, the code is a bug against this document, not the other way
around.

## What it is

Decision support for prospecting and account development, for a
**Manager** and a **Team Head** (within their own team). It answers
questions like: which leads to work first and *why*, which leads are
going cold, who needs follow-up, which opportunities are at risk, what
information is missing about a prospect, and what the next action should
be — and it can draft a follow-up message or help prepare a call plan
for a human to use.

It never sells, contacts, or changes anything. Every write and every
send still goes through the existing application services, the existing
authorization, and the existing human-confirmation screens.

## Architecture — one agent, one more tool category

The application runs **one** generic `App\Services\Ai\Agent` engine.
Each "agent" (`AgentIdentifier` case) is that same engine configured
with a different prompt and a different `ToolRegistry`. Phase 13 adds a
fifth configuration — `AgentIdentifier::BusinessDevelopment` — and
nothing else structural:

- **No** orchestrator, **no** agent swarm, **no** agent-to-agent
  messaging. `ManagementReviewOrchestrator` (Phase 9's fixed
  Performance→Sales sequence) is untouched and BD is not part of it.
- **No** new provider, **no** new audit framework (`AgentInteraction`
  captures BD calls exactly like every other agent), **no** new send
  path, **no** RAG/vector/embedding anything (BD uses the Phase 10
  Knowledge Layer's existing `SearchKnowledgeTool`).

```
Single AI Agent engine
      │
      ├── Sales tools
      ├── Performance tools
      ├── Communication tools
      ├── Cost-to-Serve tools
      └── Business Development tools ── App\Services\BusinessDevelopment\LeadIntelligenceService
                                          │
                                          ▼
                              ScopesCrmQueries / policies / PerformanceAuthorizer
                                          │
                                          ▼
                              human-confirmed action (existing services)
```

## Tools (STEP 5 — the minimum set that delivers the value)

### New, read-only, all backed by `LeadIntelligenceService`

| Tool | What it returns |
|---|---|
| `prioritize_leads` | The user's open leads ranked by a transparent points score. Each result carries its `score`, `priority_band`, the exact `factors` (label + points) that produced the score, and a deterministic `recommended_action`. |
| `identify_stale_leads` | Open leads with no activity **and** no pending follow-up for ≥ `stale_lead_days`, and not brand new. |
| `identify_follow_up_gaps` | Open leads whose follow-up is overdue or never set — using `CrmMetricsService::followUpCounts()`'s exact bucket boundaries. |
| `identify_at_risk_opportunities` | Open opportunities stalled ≥ `stalled_opportunity_days` (no activity) or past their expected close date. Each names the reason(s). |
| `analyze_account` | One organisation, split into **KNOWN** (database facts), **INFERENCE** (a labelled rule over those facts — e.g. "no Closed Won → prospect, not customer"), **MISSING INFORMATION**, and **RECOMMENDATION** (a suggested next step, never an action). Resolves by `organization_id` or an unambiguous `organization_name`; an out-of-scope organisation is reported identically to "not found". |
| `identify_missing_information` | The empty qualification-relevant CRM fields for one lead or one account. Names the gap; never fabricates a value. |

`score_lead` from the spec's tool list is folded into `prioritize_leads`
(the score *is* per-lead). Discovery questions, call plans, and meeting
agendas (spec §17) are produced by the model from `analyze_account` +
`get_lead` + `get_communication_history` output — no dedicated tool
needed.

### Reused unchanged (registered on the BD agent's own `ToolRegistry`)

`search_leads`, `get_lead`, `search_opportunities`, `get_opportunity`,
`get_followups`, `get_pipeline_summary`, `get_communication_history`,
`get_team_performance`, `draft_email`, `draft_whatsapp`,
`search_knowledge` (types: Sales Playbook, Product Guide, SOP).

`draft_email` / `draft_whatsapp` produce a **draft only** — they never
create a `Communication` row and never call `CommunicationService`'s
send methods. The only path to an actual send is the human clicking
through to the existing composer + Phase 6 confirmation screen.

### The BD agent has NO tool that can

create / update / assign / re-classify / delete a lead, opportunity,
account, contact, or target; move a lead status or opportunity stage;
send anything; or run SQL / a raw query. `AgentRegistryTest` and
`BusinessDevelopmentPromptInjectionTest` assert this structurally.

## Transparent lead prioritisation (STEP 13 — no black box)

The score is the **plain sum** of whichever factors apply. Every factor
and its points are returned alongside the number so a human can check
the arithmetic. Weights live in
`config('services.business_development.weights')`:

| Factor | Default points |
|---|---|
| Lead is Qualified | 4 |
| Lead has been Contacted | 2 |
| Manually marked High priority | 3 |
| Manually marked Medium priority | 1 |
| Follow-up is overdue | 4 |
| No follow-up date is set | 2 |
| Engaged within the last `recent_engagement_days` (7) | 2 |
| No activity ever logged | 1 |
| Already has an open opportunity | 3 |
| Estimated value ≥ `high_value_threshold` (50,000, lead's own currency) | 2 |

Band: `high` ≥ 8, `medium` ≥ 4, else `low`
(`config('services.business_development.bands')`). There is **no stored
score** — it is recomputed from live data on every call, so it can
never drift from reality.

## Authorization

- **`AgentIdentifier::BusinessDevelopment->isAvailableTo()`** — `true`
  for Manager or Team Head, `false` for a Team Member. Consulted by the
  assistant dropdown, `SendAssistantMessageRequest` validation, and the
  auto-routing fallback in `AssistantController`.
- A Team Member's business-development question is **never a 403** — it
  auto-routes, fails the eligibility check, and falls back to the Sales
  agent (which already covers lead prioritisation).
- **No feature switch.** Unlike Phase 12A's Cost-to-Serve toggle, BD is
  always on for its two roles — a switch was not requested and would be
  scope beyond this phase.
- **Record scope:** every tool and the page go through
  `LeadIntelligenceService`, which reuses
  `ScopesCrmQueries::scopeToUser()` verbatim — Manager unrestricted;
  Team Head limited to their own team; a supplied `team_id` can only
  *narrow* that scope, never widen it. Per-record tools (`analyze_account`,
  `identify_missing_information`) additionally run the entity's own
  Policy `view` check. Restricted records are reported identically to
  "not found".
- **Cost-to-Serve boundary (STEP 12 — Phase 12A is final and
  untouched):** the BD agent has no Cost-to-Serve tool and no path to
  `AccountEconomicsService`. It exposes no revenue-concentration,
  contribution, or cost-to-serve figure, and its prompt tells it to
  decline such questions as a separate, access-controlled capability.

## UI

- **`/business-development`** (`BusinessDevelopmentController@index`) —
  a read-only page linked in the sidebar's Performance group, Manager +
  Team Head only. Three scannable sections: **Today's Priorities**
  (prioritised leads with their factor breakdown), **Follow-up Gaps**,
  **At-Risk Opportunities**. Every row links to the real lead /
  opportunity so the human acts there, through normal authorization and
  audit. Empty states for every section. Existing Phase 11A design
  system components only (`flux:table`, `flux:badge`, `flux:callout`);
  light + dark.
- The assistant page shows "Business Development" in the agent dropdown
  for eligible users. Account intelligence, discovery help, and drafts
  happen in the existing conversation + draft-handoff UI — no new draft
  interface was built.

## Configuration

`config/services.php` → `business_development`
(env overrides in `.env.example`):

| Key | Default | Meaning |
|---|---|---|
| `stale_lead_days` | 10 | Cold-lead threshold. |
| `stalled_opportunity_days` | 21 | At-risk (no-activity) threshold. |
| `recent_engagement_days` | 7 | "Recent engagement" scoring window. |
| `max_results_per_query` | 25 | Hard cap on rows any BD tool returns (spec §27). |
| `high_value_threshold` | 50000 | "High estimated value" scoring factor cutoff. |
| `weights.*`, `bands.*` | see table above | Prioritisation points and band cutoffs. |

## Audit (STEP 28)

No new mechanism. Every BD agent interaction is captured by the
existing `AgentInteraction` trail — user, timestamp, message, which
tools were called with which (sanitised) arguments, status, token usage
— exactly like the other four agents. Draft bodies/subjects are
redacted from the recorded arguments by `Agent::sanitizeArguments()`,
unchanged.

## Testing

| File | Covers |
|---|---|
| `tests/Feature/BusinessDevelopment/LeadIntelligenceServiceTest` | Score = sum of named factors; terminal leads excluded; Manager/Team-Head scoping; a Team Head's crafted `team_id` only narrows; stale / follow-up-gap / at-risk rules; KNOWN/INFERENCE/RECOMMENDATION split; missing-info; `source` on every result. |
| `tests/Feature/BusinessDevelopment/BusinessDevelopmentControllerTest` | Page auth (Manager ✓, Team Head ✓, Team Member 403, guest → login); factor reasons rendered; Team Head sees only their team; empty states; at-risk section. |
| `tests/Feature/Ai/Tools/BusinessDevelopmentToolsTest` | Six tool contracts; transparent factors; auth re-derived from the actor; Team Head cross-team denial; `analyze_account` name/id resolution + out-of-scope denial; result-count bound. |
| `tests/Feature/Ai/BusinessDevelopmentAgentAccessTest` | Dropdown visibility per role; explicit-selection rejection for a Team Member; auto-routing to BD; Team Member → Sales fallback (not 403); direct `isAvailableTo()` matrix. |
| `tests/Feature/Ai/BusinessDevelopmentPromptInjectionTest` | Injected lead note never mutates the system prompt; crafted `team_id` leaks nothing; no sql/query/raw tool; no write/send/assign/close tool; no Cost-to-Serve tool; "create a lead" writes nothing; prompt carries the shared rules + KNOWN/INFERENCE/RECOMMENDATION + Cost-to-Serve boundary. |
| `tests/Feature/Ai/AgentRouterTest` | BD routing for prioritisation/stale/at-risk/call-plan/missing-info phrasing; BD checked before Communication but "draft a follow-up" still → Communication. |
| `tests/Feature/Ai/AgentRegistryTest` | Registry now lists five agents; BD's exact tool set and the tools it must not have. |

Full regression suite after Phase 13: **676 passed, 0 failed**
(`php artisan test`); `./vendor/bin/pint --test` clean.

## Known limitations

1. **No external company data** — revenue, headcount, firmographics,
   intent signals, decision-makers. BD reasons only over what is
   already in the CRM (leads, opportunities, activities, communications,
   targets). Asked for anything else it says "not available in the
   system" and names the gap. This is by design (a V1 exclusion,
   reaffirmed in `docs/V2_BACKLOG.md`), not an oversight.
2. **"Stalled" for an opportunity uses last-activity age**, not
   time-in-stage — there is no stage-history table in V1.
3. **Prioritisation weights are a defensible V1 default**, reconciled
   against worked examples in the tests, not tuned against real
   outcomes — they are config, meant to be adjusted once real usage
   data exists.
4. **No real Anthropic credentials were available while building this
   phase** (same as every prior AI phase) — every automated test uses
   `FakeLlmProvider`. Live model phrasing when explaining a priority
   score or a data gap to a Manager was not verified end to end.
5. **Visual QA** was done via blade-render feature tests (the
   codebase's established method) — see the Phase 13 gate report for
   what a live browser pass did / did not cover.
