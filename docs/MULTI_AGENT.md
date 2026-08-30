# Specialized Multi-Agent Architecture (Phase 9)

This document is the authoritative reference for the multi-agent layer
introduced in Phase 9. If this document and the code ever disagree, the
code is a bug against this document, not the other way around.

**Core principle:** specialization does not mean autonomy. Multiple
agents now exist instead of one, but every boundary from Phase 7/8 is
preserved exactly: one authorization model, one `CommunicationService`
send path, one audit model, one execution-limit mechanism, one provider
abstraction. Routing decides *which* agent answers; it never decides
*what that agent is allowed to do* — that remains each agent's own,
identical tool-enforced authorization.

> **Later additions.** Phase 9 shipped three agents; Phase 12 added a
> fourth (**Cost-to-Serve Intelligence** — see `docs/COST_TO_SERVE.md`),
> Phase 13 a fifth (**Business Development** — see
> `docs/BUSINESS_DEVELOPMENT.md`), and V2.1/V2.2 a sixth (**Market
> Intelligence** — external prospect discovery and evidence-based
> qualification, see `docs/MARKET_INTELLIGENCE.md`). Each is the same single `Agent`
> engine with a different `AgentDefinition` — no orchestrator, no
> swarm, no agent-to-agent calls were added. Everything below about the
> engine, routing, `AgentPromptRules`, the audit trail, and the
> no-autonomy boundaries applies to all six agents; only the counts
> and the per-agent tool tables in this Phase 9 document were not
> retro-edited. `AgentRegistryTest.php` is the authoritative,
> always-current tool permission matrix for every agent. The Market
> Intelligence agent is the one whose tools reach *outside* the
> application (public web search + public page fetches, always behind
> `OutboundUrlGuard`); it has no CRM, communication, or Cost-to-Serve
> tool at all.

## Architecture

```
User
  │
  ▼
AssistantController
  │
  ├─ explicit `agent` field? ──────────────► use it directly
  │
  └─ no explicit agent ──► AgentRouter.route(message)
                                │
                    ┌───────────┼──────────────┐
                    ▼           ▼               ▼
                  sales    performance     communication
                    │           │               │
                    └───────────┴───────┬───────┘
                                         ▼
                              AssistantService.respond(agentId, actor, message)
                                         │
                          AgentRegistry.get(agentId) → AgentDefinition
                                         │
                         new Agent(provider, definition.tools, definition.systemPrompt, ...)
                                         │
                                         ▼
                                  (exactly Phase 7's engine, unchanged)
```

For a genuinely cross-domain request, `AgentRouter::isManagementReviewRequest()`
diverts to `ManagementReviewOrchestrator` instead of a single agent —
see "Cross-agent workflow" below. An explicit agent choice always wins
and is never upgraded to this path.

### Reuse, not a rewrite

`App\Services\Ai\Agent` — the generic tool-calling engine — is **the
exact same class from Phase 7**, unedited. `App\Contracts\Ai\LlmProvider`
and `AnthropicProvider` are unedited. Every one of the 13 Phase 7/8
tools is unedited. What Phase 9 adds:

- **`AgentDefinition`** (`app/Support/Ai/AgentDefinition.php`) — the
  common agent contract (STEP 5): identifier, name, purpose, system
  prompt, a `ToolRegistry` (its permitted tools), which Phase 8 workflow
  type it's used for, and its own iteration limit.
- **`AgentRegistry`** (STEP 6) — a fixed map of the three approved
  `AgentDefinition`s, built once in `AppServiceProvider`. Not a plugin
  framework — nothing registers itself at runtime.
- **`AgentRouter`** (STEP 16/18) — a deterministic keyword classifier,
  not an LLM call (STEP 58 cost control). Picks which agent handles a
  free-text request; never decides authorization.
- **`ManagementReviewOrchestrator`** (STEP 20-22/37) — the one
  application-controlled cross-agent sequence.
- Three prompt classes (`SalesAgentPrompt`, `PerformanceAgentPrompt`,
  `CommunicationAgentPrompt`) sharing one `AgentPromptRules` text block
  for every hard safety rule, so the three prompts can never drift out
  of sync on a rule that matters.
- **`AssistantService`** evolved (STEP 45, explicitly directed) from
  "invoke the one agent" to "invoke whichever agent it's told to" — it
  now takes an `AgentIdentifier` and constructs a fresh `Agent` engine
  per call from that agent's own `AgentDefinition`. Everything else
  about its contract (audit recording, catching `AiProviderException`
  so the CRM never breaks) is unchanged from Phase 7.

Phase 7's original single general-purpose prompt (`CrmAssistantPrompt`)
is retired — its exact "Hard rules" text lives on unmodified as
`AgentPromptRules::text()`, included verbatim in all three specialized
prompts.

## The three agents and their tool permission matrix (STEP 24)

| Agent | Tools | Workflow it's used for |
|---|---|---|
| **Sales Intelligence** | `search_leads`, `get_lead`, `search_opportunities`, `get_opportunity`, `get_followups`, `get_communication_history`, `get_pipeline_summary` | Opportunity Attention Review |
| **Performance & Management** | `get_my_performance`, `get_team_performance`, `get_pipeline_summary` | Performance Exception Review |
| **Communication & Follow-Up** | `get_followups`, `get_communication_history`, `get_lead`, `get_opportunity`, `draft_email`, `draft_whatsapp` | Daily Follow-Up Review |

No agent has a send tool. `get_pipeline_summary` is intentionally shared
between Sales and Performance (STEP 23 explicitly permits shared
tools) — each agent gets its **own** instance of the tool via its own
`ToolRegistry`, never a shared mutable object. `search_contacts`/
`get_contact` (built in Phase 7) are not assigned to any of the three
agents — the spec's own tool lists for each agent (STEP 8/11/14) don't
name them, and `get_lead`/`get_opportunity`'s own curated output already
includes a contact's name where relevant. `AgentRegistryTest.php`
asserts both halves of this matrix — every tool an agent should have,
and every tool it should not.

## Routing (STEP 16-18)

`AgentRouter::route()` is a plain keyword classifier — deliberately not
an LLM call, both for cost (STEP 58: an extra AI round-trip just to
decide which agent to ask isn't worth it) and for determinism (a router
you can unit-test exhaustively is safer than one whose behavior can only
be observed by calling a real model). Communication-intent keywords
("draft", "email", "whatsapp", "send", "follow up") are checked first,
so "draft a follow-up about the stalled ABC opportunity" correctly lands
on the one agent that can actually produce a draft, despite mentioning
sales vocabulary. An ambiguous request with no matched keyword defaults
to Sales — the closest analog to Phase 7's original general assistant
(STEP 45 backward compatibility).

**Routing is not security** (STEP 18): every test that proves this
(`AssistantControllerTest`, `ToolsTest`, `SecurityAndInjectionTest`)
shows a *deliberately* misrouted request still can't leak unauthorized
data, because authorization lives entirely inside each tool, identical
regardless of which agent called it.

### Explicit selection (STEP 17)

The assistant UI (`/assistant`) offers an "Ask" dropdown — Sales /
Performance / Communication / "Auto — let the assistant pick". An
explicit choice always wins over routing, and is never silently
upgraded to the cross-agent Management Review path even if the message
text would otherwise trigger it.

## Cross-agent workflow: Management Review (STEP 20-22, 37, 38)

The **only** multi-agent sequence in Phase 9. Detected by
`AgentRouter::isManagementReviewRequest()` (an explicit "management
review"/"sales review" phrase, or the message matching both Performance
and Sales keywords at once) — never by an agent's own judgment.
`ManagementReviewOrchestrator::run()` is plain Laravel control flow
(STEP 22 — not an LLM "which agents should I spawn" decision):

1. Call the Performance Agent with the original request.
2. Call the Sales Agent with the original request.
3. Combine both into a `ManagementReviewResult`, clearly sectioned
   (`PERFORMANCE` / `SALES PIPELINE`), never merged into one
   undifferentiated blob.

Each sub-agent call is a completely ordinary `AssistantService::respond()`
call — same authorization, same audit row. **Neither agent ever sees the
other's output** (STEP 30/31/34): each receives only the original
request text, nothing more. This is what makes agent-to-agent prompt
injection structurally impossible here, not merely discouraged by
wording — `ManagementReviewOrchestratorTest` proves an injection string
planted in the Performance Agent's own response never reaches the Sales
Agent's input at all.

If one sub-agent fails, the other's result is still returned, and the
combined summary says so honestly ("Performance analysis could not be
retrieved") rather than fabricating a number (STEP 38).

There is no other cross-agent path anywhere in this codebase. Arbitrary
agent-to-agent delegation is disabled by construction: no `AgentTool`
implementation calls `AssistantService`, `Agent`, or any other agent —
tools only ever call existing Phase 3-6 application services.
Recursion is therefore structurally impossible, not merely avoided by
convention (`ManagementReviewOrchestratorTest::test_it_never_recurses...`
confirms no tool's name even resembles an agent-invoking capability).

## Phase 8 workflow → agent mapping (STEP 44)

| Workflow | Agent | Why |
|---|---|---|
| Daily Follow-Up Review | Communication & Follow-Up | It's a review of who to contact — exactly that agent's specialty, and the only one with drafting tools. |
| Opportunity Attention Review | Sales Intelligence | Pipeline/opportunity signals are exactly its specialty. |
| Performance Exception Review | Performance & Management | Target/pace/coverage exceptions are exactly its specialty. |

`WorkflowExecutionService::run()` now takes an explicit `AgentIdentifier`
argument — each of the three Phase 8 job classes passes its own fixed
mapping above; `WorkflowExecutionService` itself has no opinion on which
agent is "correct" for a workflow, it only executes whichever one it's
told. Every other Phase 8 guarantee (idempotency, cost-control skip,
draft → persisted `WorkflowApproval`, revalidated send) is completely
unchanged — `WorkflowExecutionServiceTest`/`ApprovalFlowTest`
(Phase 8's full suite) all still pass unmodified except for the one new
required constructor argument.

## Authorization (STEP 25)

Unchanged from Phase 7/8: every tool call re-derives the actor's
authorization from the real authenticated `User` passed into it, never
from which agent was chosen or how the request was routed. There is no
"system user" or elevated identity anywhere in this layer — a Manager
gets Manager-scoped tool results, a Team Head gets team-scoped results,
a Team Member gets only their own, regardless of which of the three
agents answered.

## Audit (STEP 26-27)

`AgentInteraction.agent` now stores which of the three specialized
agents actually answered (`sales`/`performance`/`communication`) instead
of the Phase 7 constant `crm-assistant`. A Management Review produces
**two** ordinary `AgentInteraction` rows (one per sub-agent), both under
the same user, both fully independent audit records — nothing new was
added to the schema for this, since two normal rows already capture
"agent selected, tools used, result, status" per sub-call. Nothing here
stores hidden chain-of-thought or the system prompt itself (unchanged
from Phase 7).

## Cost control (STEP 57-58)

- Routing costs zero AI calls (pure PHP keyword matching).
- A single-domain question invokes exactly one agent, one `AgentInteraction`
  row, one `Agent` tool-loop.
- The cross-agent path only triggers on a genuinely cross-domain signal,
  never by default, and never when the user explicitly picked one agent.
- `AI_MAX_TOOL_ITERATIONS` (per-agent, from each `AgentDefinition`) still
  bounds every individual agent call exactly as in Phase 7.

## Provider independence (STEP 29)

All three agents, the router, and the orchestrator depend only on
`App\Contracts\Ai\LlmProvider` — none of them reference
`AnthropicProvider` or any Anthropic-specific type. Swapping providers
still means writing one new class and rebinding the interface in
`AppServiceProvider`; no agent code changes.

## Known limitations

1. **Routing is a deterministic heuristic, not a learned classifier.**
   A sufficiently unusual phrasing could route to a less-ideal (but
   still fully authorized and safe) agent — this is a quality trade-off
   made deliberately for cost/predictability (STEP 58), not a security
   concern, since every agent enforces the same authorization
   regardless.
2. **The Management Review trigger is also heuristic** (an explicit
   phrase, or both Performance+Sales keywords present). It will not
   catch every possible phrasing of a genuinely cross-domain request —
   a user can always get both analyses by asking two separate questions
   instead, or by adding a phrase like "management review" explicitly.
3. **No real Anthropic credentials were available while building this
   phase** (same situation as Phase 7/8) — every automated test uses
   `FakeLlmProvider`/`Http::fake()`. Live routing/agent-selection
   behavior against the real model, and whether real model responses
   read naturally when combined by the orchestrator, have not been
   verified.
4. **`search_contacts`/`get_contact`** (built in Phase 7) remain
   unassigned to any of the three agents, per the specification's own
   tool lists — they still exist and are still fully tested, simply
   unused by the current agent set. A future agent could adopt them
   without any change to the tools themselves.
