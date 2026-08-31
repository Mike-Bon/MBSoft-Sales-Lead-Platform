# AI Assistant (Phase 7)

> **Superseded by Phase 9.** The single-agent architecture this document
> describes (one `Agent` engine instance bound directly in
> `AppServiceProvider`, driven by a single `CrmAssistantPrompt`) has been
> replaced by three specialized agents sharing the same underlying
> engine — see **docs/MULTI_AGENT.md** for the current architecture.
> Everything below about the `Agent` engine itself, `LlmProvider`, the
> 13 tools, the draft/approval flow, and every non-negotiable safety
> boundary remains accurate and fully reused; only the "one agent" /
> `AppServiceProvider` wiring section immediately below is now
> historical — kept for context on why the engine was built generic
> from day one.

This document is the authoritative reference for the constrained AI agent
introduced in Phase 7. If this document and the code ever disagree, the
code is a bug against this document, not the other way around.

**Core principle, binding on every future phase:** the AI is never the
source of truth, never the authorization layer, never the database
layer, and never the external communication provider. It is a
constrained reasoning layer operating strictly inside the application
architecture built in Phases 1–6.

## Architecture

```
User → AssistantController → AssistantService → Agent (generic engine)
                                                    │
                                    ┌───────────────┼────────────────┐
                                    ▼                                ▼
                              LlmProvider                      ToolRegistry
                    (GeminiProvider default /            (13 AgentTool instances)
                     AnthropicProvider fallback)
                                                                     │
                                                     each tool calls an existing
                                                     application service/policy
                                                                     │
                                                                     ▼
                                              Authorization / Policies / RLS
                                                                     │
                                                                     ▼
                                                       Database / CommunicationService
```

For any external action (email/WhatsApp): `Agent` → a draft tool →
structured draft data → human review on the existing Phase 6 composer →
`CommunicationService` → provider. The agent itself never touches a
provider or the database.

### One agent, not a multi-agent architecture

There is exactly one agent process. `App\Services\Ai\Agent` is a
**generic** tool-calling engine — constructor-configured with a system
prompt, a `ToolRegistry`, an `LlmProvider`, and an iteration limit. It
has no knowledge of "CRM", "sales", or any specific prompt. The one
Phase 7 agent is a single bound instance of this engine, configured in
`AppServiceProvider` with `CrmAssistantPrompt::text()` and the full tool
list. "Sales", "Performance", and "Communications" are tool categories
available to that one agent — never separate agent processes, and there
is no orchestrator, agent registry, or agent-to-agent delegation
anywhere in this codebase.

This is a deliberate forward-compatibility choice: a future phase could
construct a **second** `Agent` instance with a different prompt and a
different (possibly overlapping) `ToolRegistry`, reusing the exact same
`Agent` engine, `LlmProvider` interface, and every existing `AgentTool`
implementation, without modifying any of them. Building that second
agent is explicitly out of scope for Phase 7.

## LLM provider

- **Provider:** Google Gemini (`generateContent` REST API,
  `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`),
  chosen as the V2.0.0 default. Anthropic (Claude) is retained as a
  supported fallback. Both have native tool-use/function-calling that
  matches this architecture directly and neither ships an official PHP
  SDK — so, exactly like Phase 6's WhatsApp Cloud API integration,
  plain HTTPS via Laravel's `Http` facade against the documented REST
  API is the correct approach.
- **Provider abstraction:** `App\Contracts\Ai\LlmProvider`, implemented
  by `App\Services\Ai\Providers\GeminiProvider` (default) and
  `App\Services\Ai\Providers\AnthropicProvider` (fallback). The concrete
  class is selected in `AppServiceProvider` from `LLM_PROVIDER`; an
  unrecognised value binds `MisconfiguredLlmProvider`, which fails the
  same safe way a missing key does (assistant unavailable, CRM
  unaffected, real reason logged) rather than silently substituting a
  working provider. `Agent`/`AssistantService`/every `AgentTool` depend
  only on the interface — swapping provider or model touches only
  `config/services.php`, `.env`, and (for a genuinely new provider) one
  new class. `Agent`, the `LlmProvider` interface, `AiCompletionResult`,
  `ToolCall`, `ToolDefinition` and every tool were unchanged by the
  Gemini swap.
- **Model/config:** `LLM_PROVIDER` (default `gemini`), `LLM_API_KEY`,
  `LLM_MODEL` (default `gemini-3.6-flash`), `LLM_MAX_TOKENS`,
  `LLM_TIMEOUT_SECONDS` — all environment-driven
  (`config/services.php`'s `llm` block). Never hard-coded, never
  logged, never exposed to the browser. The model must support function
  calling; keep it entirely env-driven so a later model change needs no
  code edit.
- **Gemini billing:** a Gemini **API** key (Google AI Studio) has its
  own quota/billing on a Google AI Studio / Google Cloud project — it
  is **separate** from any consumer "Gemini" app subscription.
- **Tool-call correlation:** Gemini's `functionCall`/`functionResponse`
  parts carry only an *optional* `id`. `GeminiProvider` round-trips a
  real id when Gemini supplies one and otherwise synthesises a
  deterministic `"{name}#{index}"` id, so `Agent` associates every tool
  result with its call. On the return trip it always sends the function
  `name` (Gemini's primary key) in call order — which is why parallel
  and repeated same-function calls stay correctly matched.
- **Thought signatures (Gemini 3+):** each model turn that emits a
  `functionCall` carries an opaque `thoughtSignature`; Gemini rejects
  the next request (HTTP 400) unless it is replayed verbatim on the same
  part (for parallel calls, only the first `functionCall` part carries
  one). `GeminiProvider` captures it into the opaque
  `ToolCall::$providerSignature` field on parse and re-attaches it
  byte-for-byte on replay — never decoded, fabricated, logged, shown to
  users, exposed to tools, or written to the audit trail. `Agent.php` is
  unchanged: it round-trips `ToolCall` objects without interpreting the
  field. Anthropic has no equivalent and leaves it null. **Known minor
  gap:** the trailing *text-part* signature (which Gemini marks
  "recommended", not required, and whose absence does not cause the 400)
  is not preserved.
- **Search is not the LLM:** Gemini is only the reasoning/tool-selection
  model. It is given exactly the ToolRegistry's function declarations
  and nothing else — no Google Search grounding, URL-context tool, code
  execution, or built-in retrieval. External web discovery stays with
  Brave (`SEARCH_PROVIDER=brave`, see `docs/MARKET_INTELLIGENCE.md`).

## Tool categories and the 13 tools

All tools are read-only or draft-only. **No write tools exist at all** —
this is enforced structurally (there is no `create_lead`/`update_lead`/
`send_email` tool anywhere in the registry), not by asking the model to
refrain.

| Category | Tools |
|---|---|
| A. Sales/CRM read | `search_leads`, `get_lead`, `search_contacts`, `get_contact`, `search_opportunities`, `get_opportunity` |
| B. Performance read | `get_my_performance`, `get_team_performance`, `get_pipeline_summary` |
| C. Communication read | `get_communication_history` |
| D. Communication draft | `draft_email`, `draft_whatsapp` |
| E. Follow-up/Activity read | `get_followups` |

Every tool (`app/Services/Ai/Tools/`) reuses an existing Phase 3–6
service, policy, or authorization primitive — never a duplicated
calculation or a raw database query:

- CRM search/get tools reuse `ScopesCrmQueries::scopeToUser()` (the
  exact same authorization the CRM controllers use) and each model's
  own Policy `view` gate.
- Performance tools reuse `PerformanceService`/`PerformanceAuthorizer`/
  `CrmMetricsService` verbatim — a tool never computes a target,
  achievement, gap, or pipeline number itself; it returns exactly what
  `PerformanceService` already calculated.
- `get_followups` reuses `CrmMetricsService::overdueLeads()` and the
  exact same bucket-boundary definitions `followUpCounts()` established
  for the dashboards.
- `get_communication_history` reuses
  `CommunicationAuthorizer::authorizeCrmAttachment()`.
- `draft_email`/`draft_whatsapp` reuse
  `CommunicationAuthorizer`/`CommunicationService::previewTemplate()`
  (a new public method added to `CommunicationService` specifically so
  template rendering is never duplicated) — see "Draft flow" below.

Every tool that accepts a `team_id`/`scope` parameter **re-derives** the
actor's authorization from the actor's own stored role/team, exactly
like every controller in this application — a requested value can only
ever narrow the result, never widen it. `search_leads`/
`search_opportunities` with a foreign `team_id` silently return zero
results (the query was already scoped before the filter was applied);
`get_team_performance`/`get_pipeline_summary`/`get_followups` with an
unauthorized `team_id`, or `scope=organisation` from a non-Manager,
throw an `AuthorizationException`.

## Draft flow — never an autonomous send

`draft_email`/`draft_whatsapp` **never** create a `Communication` row
and **never** call `CommunicationService::sendEmail()`/`sendWhatsApp()`.
Each returns pure structured data (recipient/subject/body/CRM
references). `AssistantController` surfaces that structure as a "pending
draft" in the session; the assistant's chat view renders a preview card
with a single "Review & Send" action that navigates to the **existing,
already-tested Phase 6 composer**
(`CommunicationController::composeEmail`/`composeWhatsApp`), pre-filled
via query parameters. Nothing about the composer's own behavior changed:
the same server-validated confirmation checkbox
(`SendEmailRequest`/`SendWhatsAppRequest`'s `confirm` rule) still applies,
and `CommunicationService` is still the only path that ever creates a
`Communication` row or dispatches `SendCommunicationJob`. A new user
message clears any previously pending draft, so a stale draft can never
be actioned against a later, unrelated conversation turn.

If a drafted WhatsApp message has more than one authorized business
number available, `draft_whatsapp` returns the list of candidates rather
than guessing — the agent is expected to ask the user which one, then
retry with `whatsapp_number_id`.

## Authorization

The authenticated application `User` is the only security principal.
The model's own stated understanding of a user's role/team is never
trusted or consulted for an authorization decision — every tool
re-derives it from `$actor` (the real, authenticated Eloquent `User`
passed into `execute()`), exactly the same way every controller in this
application does. See `ToolsTest.php` and `PromptInjectionTest.php` for
the tests that hold this boundary even when a (simulated) compromised
model tries to ask around it.

## Prompt-injection defense

All CRM content (lead/contact/organization names, notes, descriptions,
message bodies) is treated as untrusted data:

1. **Structural separation.** `LlmProvider::complete()` takes the system
   prompt as a wholly separate parameter from the conversation.
   `GeminiProvider` sends it via Gemini's own `systemInstruction` field
   and `AnthropicProvider` via Anthropic's `system` field — never
   concatenated into the conversation. CRM content only ever enters
   a conversation as `tool_result` content — it can never become part of
   the system prompt, because nothing in this codebase ever does that.
2. **System instructions** (`CrmAssistantPrompt::text()`) explicitly
   tell the model to treat all tool output as untrusted data, never as
   instructions, and to never follow an instruction embedded in it.
3. **No tool can do anything dangerous even if "obeyed".** There is no
   `send_email`/`delete_lead`/`update_target` tool to call — if a
   (simulated) compromised model tries to call one anyway, `Agent`
   reports "Unknown tool" and nothing happens. This is the real defense;
   the prompt wording is a second layer, not the only one.
4. **Authorization can't be argued around.** Even a tool the model *can*
   call (`search_leads` with another team's `team_id`) still only
   returns what `$actor` is actually authorized to see.

`PromptInjectionTest.php` and `EvaluationSuiteTest.php`'s example 7
exercise all of this deterministically via `FakeLlmProvider`, which
plays the part of a "compromised" model — a real, non-deterministic LLM
can't be asserted against in an automated suite (STEP 28), so these
tests prove the *system* holds regardless of what the model does, rather
than testing the model's own judgment.

## Execution limits (STEP 27)

- `AI_MAX_TOOL_ITERATIONS` (default 6): `Agent` stops after this many
  provider round-trips and returns a safe "I wasn't able to finish that"
  message rather than looping — verified by
  `AgentTest::test_reaching_the_max_tool_iterations_stops_safely`.
- `AI_MAX_MESSAGE_LENGTH` (default 2000): enforced by
  `SendAssistantMessageRequest`.
- `AI_HISTORY_TURNS` (default 6): only this many recent conversation
  turns are replayed to the model — bounded token usage, no unbounded
  history growth.
- `LLM_TIMEOUT_SECONDS` (default 30): the HTTP timeout on the provider
  call itself.
- Rate limiting: `throttle:20,1` on the message-sending route.

## Failure handling (STEP 28)

`AssistantService::respond()` catches `AiProviderException` (auth
failure, rate limit, timeout, connection failure, missing config) and
returns a safe, generic "temporarily unavailable" message — it never
throws up to the controller. The rest of the CRM has no dependency on
the AI layer at all; every other route continues to function normally
if the AI provider is down, misconfigured, or simply not set up (no API
key configured). Verified by `AssistantServiceTest`.

## Auditability (STEP 35)

Every assistant request/response cycle writes one `AgentInteraction`
row: user, agent identifier, provider, model, status, the request/
response text (bounded to 4000 characters), sanitized tool calls (name +
arguments — **not** full tool results, and a `draft_email`/
`draft_whatsapp` call's `subject`/`body` arguments are redacted to
`[redacted]` before storage, since they can carry actual drafted message
content that would otherwise duplicate customer-facing data into a
second table), token usage, and timing. The system prompt itself, and
any hidden chain-of-thought, are never stored.

## What was deliberately not built (STEP 50)

Multi-agent architecture, an orchestrator, agent-to-agent delegation,
autonomous CRM writes of any kind, autonomous email/WhatsApp sending, AI
target/user/permission modification, arbitrary SQL or code execution,
Obsidian, RAG/vector search, social-media automation, predictive
lead-scoring, and autonomous prospecting. None of these exist anywhere
in this codebase.

## Known limitations

1. **No streaming.** The chat is a plain synchronous form post (matching
   this application's existing Blade/session-form architecture — see
   `AssistantController`'s docblock) — a response only appears after the
   full tool loop completes, not token-by-token.
2. **Conversation history is session-based**, not a persisted
   `Conversation` model. It's cleared on logout (`Session::invalidate()`
   already does this — verified by
   `AssistantControllerTest::test_logging_out_clears_the_conversation`)
   and via the explicit "New conversation" action, but is not itself an
   audit record — `AgentInteraction` is the durable audit trail
   regardless of what happens to the session.
3. **No real LLM credentials were available while building this.** Every
   automated test uses `FakeLlmProvider` or `Http::fake()` against
   `GeminiProvider`'s / `AnthropicProvider`'s real wire-format code —
   never a real API call. Live behaviour against the real Gemini API
   (does the real model actually resist a real injection attempt, does
   it choose sensible tools for an ambiguous request, does it emit
   `functionCall` `id`s or rely on name+order, does real latency fit
   comfortably under the timeout) has **not** been verified and must not
   be assumed to work until someone with a real `LLM_API_KEY` exercises
   it manually.
4. **`get_pipeline_summary`/`get_followups`'s "team" scope trusts the
   actor's own `team_id` as the default** when no `team_id` argument is
   given — this is the same default every dashboard already uses, not a
   new trust decision.
