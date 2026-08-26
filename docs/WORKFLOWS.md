# Controlled Agentic Workflows (Phase 8)

This document is the authoritative reference for the workflow layer
introduced in Phase 8. If this document and the code ever disagree, the
code is a bug against this document, not the other way around.

**Core principle:** the workflow makes the AI proactive. It does not
make the AI sovereign. Every architectural boundary from Phase 7 —
provider isolation, one agent, tool-only access, authorization
re-derivation, prompt-injection defenses, execution limits — is reused
unmodified. Phase 8 adds exactly two new concepts on top: *when* to run
(the workflow), and a *persisted* approval for output produced while no
human was watching.

## Architecture

```
Scheduler (routes/console.php, daily)
        │
        ▼
workflows:run-daily  (one job dispatched per workflow type × user)
        │
        ▼
Queued Job (DailyFollowUpReviewJob / OpportunityAttentionReviewJob / PerformanceExceptionReviewJob)
        │
        ▼
Analyzer  ── deterministic Laravel logic only (STEP 37) ──▶ AnalysisResult
        │
        ▼
WorkflowExecutionService::run()
        │
        ├─ no findings? → record a plain "all clear" result. Agent never called (cost control, STEP 36).
        │
        └─ findings? → WorkflowPromptBuilder builds a WORKFLOW CONTEXT/TASK/DATA/RULES message
                              │
                              ▼
                    AssistantService::respond()   ← the exact Phase 7 agent, unmodified
                              │
                              ▼
                    AgentResponse (text + optional draft)
                              │
              ┌───────────────┴────────────────┐
              ▼                                 ▼
    WorkflowExecution (result, audit)   WorkflowApproval (if a draft was produced)
                                                  │
                                                  ▼
                                    Human reviews in /workflows/approvals
                                                  │
                                    "Review & Send" → existing Phase 6 composer,
                                    prefilled — CommunicationService remains the
                                    only path that ever sends anything.
```

### Reuse, not a second AI architecture

Nothing in Phase 8 talks to `LlmProvider` directly, builds a second
`ToolRegistry`, or writes a second audit model. `WorkflowExecutionService`
calls `App\Services\Ai\AssistantService::respond()` — the identical
method the Phase 7 chat controller calls — so a workflow run gets the
same `Agent` engine, the same 13 tools (including `draft_email`/
`draft_whatsapp`), the same `CrmAssistantPrompt` system instructions, the
same prompt-injection structural defenses, the same tool-iteration limit,
and the same `AgentInteraction` audit row, automatically. The only new
code is:

- **`WorkflowExecutionService`** — idempotency, cost-control skip, and
  turning a produced draft into a persisted `WorkflowApproval` instead
  of an ephemeral chat-session draft.
- **`WorkflowPromptBuilder`** — formats the WORKFLOW CONTEXT/TASK/DATA/
  RULES *user-turn content* (not a new system prompt).
- Three **analyzers** — plain deterministic Laravel/Eloquent queries,
  reusing `CrmMetricsService`/`PerformanceService` exactly.

## The three workflows

| Workflow | Analyzer | Signals (all deterministic) |
|---|---|---|
| Daily Follow-Up Review | `DailyFollowUpAnalyzer` | Overdue/due-today leads, via `CrmMetricsService::overdueLeads()`/`followUpCounts()` — the exact dashboard definitions. |
| Opportunity Attention Review | `OpportunityAttentionAnalyzer` | Closing soon (configurable window), no recent activity ("stalled"), missing expected close date. Never a predicted outcome — see "Language discipline" below. |
| Performance Exception Review | `PerformanceExceptionAnalyzer` | `ManagementSignal::Behind`/`AtRisk` and low pipeline coverage, using `PerformanceSnapshot::managementSignal()` and `ManagerDashboardService`'s own low-coverage predicate — not a new formula. |

Deterministic thresholds (`stalled_opportunity_days`, `closing_soon_days`)
are `config('services.workflows.*')`, not agent-decided.

### Language discipline (STEP 7/38)

`OpportunityAttentionAnalyzer` never outputs a prediction — its findings
vocabulary is limited to factual signals ("closing soon", "no recent
activity", "missing expected close date"). The workflow prompt
(`WorkflowPromptBuilder`) explicitly instructs the agent to distinguish
CRM facts from its own recommendations and never invent information
beyond the supplied DATA.

## Scope and authorization (STEP 23/24)

`WorkflowScope::forUser()` derives exactly one of three scopes from the
subject user's own stored role — never elevated, never client-supplied
(there is no HTTP request in a scheduled run at all):

- **Manager → Organisation.** Every analyzer queries unscoped (the same
  "Manager sees everything" rule used throughout this application).
- **Team Head → Team.** Scoped to `team_id`, identical to
  `ScopesCrmQueries`'s own rule.
- **Everyone else → Individual.** Scoped to `owner_id`.

The agent, when invoked, runs as `$scope->subject` — if it uses any tool
(`search_leads`, `get_lead`, etc.) for more detail, that tool enforces
exactly the same authorization a real request from that user would.
`SecurityAndInjectionTest.php` proves a workflow can never be made to
leak a foreign team's data even if the model tries.

## Idempotency (STEP 12)

Every execution has a deterministic `execution_key`:
`{workflow}:{scope-id}:{date}` (e.g. `daily_follow_up_review:team-3:2026-08-28`).
A unique database index on this column is the actual guarantee — not
merely application-level care — backed up by a pre-check and a
`UniqueConstraintViolationException` catch for the race where two
dispatches of the same job land concurrently. The same (workflow, scope,
day) can never produce two executions, two agent calls, or two
approvals.

## Cost control (STEP 36/37)

The agent is **never called** when an analyzer finds nothing — the
execution is recorded immediately as `Completed` with the analyzer's own
plain "all clear" message. Only records an analyzer already
deterministically identified as relevant are ever included in the
prompt's DATA section; nothing asks the model to search or filter raw
CRM data itself.

## Draft → Approval → Send (STEP 19/20/21/40/41)

A `draft_email`/`draft_whatsapp` tool call during a workflow run behaves
identically to Phase 7's interactive assistant — it never creates a
`Communication` row and never sends anything. `WorkflowExecutionService`
turns that structured draft into a **persisted** `WorkflowApproval`
(Phase 7's chat draft is ephemeral session state; a workflow runs
unattended, so its output must survive until a human reviews it later).

"Review & Send" on the approvals queue (`/workflows/approvals`) routes
to the exact same Phase 6 composer Phase 7's chat draft already reuses
(`CommunicationController::composeEmail`/`composeWhatsApp`, now also
accepting `workflow_approval_id` as a prefill parameter). The same
server-validated `confirm` checkbox still applies — there is no second,
lighter-weight send path.

### Revalidation (STEP 40), inside `CommunicationService`

When a send request carries `workflow_approval_id`,
`CommunicationService::resolveWorkflowApproval()` verifies, **before any
side effect**:

1. The approval exists and belongs to the acting user.
2. It is still `Pending` and not expired (`WorkflowApproval::isActionable()`).
3. (Already covered by the ordinary send path) the referenced CRM
   record still exists and the actor may still view it —
   `CommunicationAuthorizer::authorizeCrmAttachment()` throws otherwise,
   satisfying "verify the CRM record still exists" for free.

A stale/expired/foreign/already-decided reference **blocks the send
entirely** with a validation error — it is never silently ignored. Once
approved, the approval's status flips to `Approved`; a second attempt to
send against the same approval id fails the "still Pending" check,
preventing a duplicate send (STEP 40 point 46).

### Expiration (STEP 39)

`WorkflowApproval::isExpired()`/`isActionable()` compute expiry
dynamically from `expires_at` — a `Pending`-status row past its expiry
is treated as expired the moment it's checked, with no separate cleanup
job required. `WORKFLOW_APPROVAL_TTL_DAYS` (default 3) controls how long
a proposal stays actionable.

## Audit trail (STEP 42/45)

`WorkflowExecution` records: workflow type, trigger, status, scope,
`execution_key`, the analyzer's `findings` (structured facts), the
agent's `result` text, a link to the `AgentInteraction` row (which
itself carries sanitized tool calls and usage), and `error_summary` on
failure. `WorkflowApproval` records the proposed action and its full
decision trail (`status`, `decided_at`, `decided_by`). A sent
`Communication` links back via `workflow_approval_id`. Nothing here
duplicates a message body into a third table — `WorkflowApproval.body`
*is* the proposed content; the eventual `Communication.body` is the
same content the human reviewed (and could have edited) before sending.

## Failure handling and retries (STEP 43/44)

- **LLM failure**: `AssistantService` already catches
  `AiProviderException` and returns a safe `Failed` response (Phase 7,
  reused) — `WorkflowExecutionService` maps this to
  `WorkflowStatus::Failed` with a safe `error_summary`, never a raw
  exception message. `WorkflowExecutionServiceTest` verifies this
  doesn't throw.
- **Tool failure**: caught inside `Agent` itself (Phase 7, reused) —
  reported back to the model as a normal tool error, never aborts the
  workflow.
- **Job failure**: `tries = 2`, a single 30-second backoff, a 120-second
  timeout — deliberately conservative, since a retry re-enters
  `WorkflowExecutionService::run()`, which is idempotent by
  `execution_key` and therefore safe to retry without creating a
  duplicate execution or a duplicate approval.
- **External send retries** are entirely Phase 6's existing concern
  (`SendCommunicationJob`) — Phase 8 never bypasses it and adds no new
  retry logic around an actual provider call.

## Scheduler (STEP 10)

`routes/console.php` schedules `workflows:run-daily` once a day at
`WORKFLOW_RUN_AT` (default `08:00`), using Laravel's own scheduler —
`->withoutOverlapping()` and `->onOneServer()`, no custom scheduler. The
command itself only decides *who* gets *which* workflow jobs dispatched
(respecting the `WORKFLOW_*_ENABLED` toggles); all actual work happens
in the queue.

## Configuration (STEP 30)

`config('services.workflows')` — enable/disable each of the three
workflows, the daily run time, the approval TTL, and the two
deterministic thresholds. No workflow builder, no user-editable
schedule per role, no arbitrary new workflow types — this is the
complete, closed configuration surface for V1.

## UI (STEP 53/54/55)

- **`x-ai.insights-card`** — a small, additive component on all three
  dashboards (Manager/Team Head/Team Member) showing the latest run of
  each workflow (linking to its detail page) and a pending-approval
  count (linking to the queue). Not a redesign, not a command center.
- **`/workflows`** — "AI Activity": every execution for the current
  user's own scope, what ran, when, and its result.
- **`/workflows/{execution}`** — one execution's full detail: summary,
  the underlying deterministic findings (labeled as CRM data, not AI
  output), and any proposals it produced.
- **`/workflows/approvals`** — the approval queue: pending proposals
  with "Review & Send" / "Reject", and recently decided ones.

## Known limitations

1. **No expiry-sweep job.** An expired approval is correctly blocked at
   send time and correctly computed as expired for display
   (`isExpired()`), but its `status` column is never proactively flipped
   to `Expired` in the database by a background job — this is a
   deliberate simplification (STEP 39 only requires verifying validity
   "at minimum... before execution", which is satisfied), not a security
   gap.
2. **No Manager oversight view of a Team Head's own workflow executions
   or approvals.** Each execution/approval is visible only to the exact
   user it belongs to (`WorkflowExecutionPolicy`/`WorkflowApprovalPolicy`).
   This is the strictest reading of least privilege for V1; a future
   phase could deliberately add a scoped oversight view if wanted.
3. **The daily command dispatches one job per workflow per user**,
   without an upfront "does this user have any relevant records at all"
   pre-filter — cost control instead happens per-execution (the agent is
   skipped when an analyzer finds nothing). At this application's
   expected scale (1 Manager + 10 Team Heads + their members) this is
   not a meaningful cost concern; worth revisiting only if the user base
   grows substantially.
4. **No real Anthropic credentials were available while building this
   phase** (same situation as Phase 7) — every automated test uses
   `FakeLlmProvider`. Live workflow runs against the real model (does it
   choose sensible priorities, does it draft reasonable follow-ups) have
   not been verified.
