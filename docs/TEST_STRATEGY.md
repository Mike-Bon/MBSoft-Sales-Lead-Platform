# Phase 11 — Test Strategy and Results

## Strategy

Every phase in this project has followed the same discipline (see
CLAUDE.md's testing bar): feature tests for authentication, policies,
request validation, role/team isolation, CRUD workflows, and dashboard
scoping; unit tests for calculations and lifecycle rules; fakes/mocks
for every external provider (Gmail, WhatsApp Cloud API, the Anthropic
LLM) so **no automated test ever makes a real external call**; and
explicit unhappy-path coverage (forbidden access, invalid transitions,
duplicate webhooks/jobs, failed providers, malformed agent output).
Phase 11 did not change this strategy — it audited it for completeness
against the full V1 surface and closed the gaps found (notifications,
audit logging, knowledge-layer injection coverage — see
`docs/PHASE_11_AUDIT.md`).

**No automated test sends real email/WhatsApp or invokes a billable AI
call.** Every provider (`EmailProvider`, `WhatsAppProvider`,
`LlmProvider`) is bound to a fake/anonymous-class implementation or
`FakeLlmProvider` in every test that would otherwise reach one. This
was true before Phase 11 and re-verified: no test file references a
real Anthropic/Google/Meta endpoint.

## Coverage by required area (Phase 11 §2 checklist)

| Area | Covered by | Status |
|---|---|---|
| Authentication & user lifecycle | `tests/Feature/Auth/*` (login, email verification, password reset/confirm, registration-disabled) | ✅ |
| Manager portal | `Dashboard/ManagerDashboardTest`, `Organisation/*` | ✅ |
| Team Head portals (all 10 — one code path, role-scoped) | `Dashboard/TeamHeadDashboardTest`, `Organisation/TeamAuthorizationTest`, `Crm/*AuthorizationTest` — proven for arbitrary teams via factories, not hard-coded to one team | ✅ |
| Role-based authorization & RLS | `*AuthorizationTest.php` (9 files), `*SecurityTest.php` (3 files), RLS verified directly against real Supabase every phase | ✅ |
| Leads/accounts/opportunities/activities | `Crm/LeadTest`, `OpportunityTest`, `ActivityTest`, `AssignmentTest`, `ContactAuthorizationTest`, `OrganizationAuthorizationTest` | ✅ |
| Targets, performance, dashboards, filtering | `Performance/*` (7 files), `Dashboard/*` (8 files) | ✅ |
| Exports | N/A — no export functionality exists in V1 (dashboards/lists only); not a gap, per Phase 11's own "where present" qualifier | N/A |
| Gmail/WhatsApp draft/approval/send flow | `Communications/*` (10 files), `Workflow/ApprovalFlowTest` | ✅ |
| Three-agent architecture, guardrails, approvals, failure states | `Ai/AgentRegistryTest`, `AgentRouterTest`, `AgentTest`, `AssistantServiceTest`, `AssistantControllerTest`, `ManagementReviewOrchestratorTest`, `PromptInjectionTest`, `EvaluationSuiteTest`, `ToolsTest` | ✅ |
| Knowledge layer: indexing, search quality, permissions, source visibility, empty/error states | `Knowledge/*` (5 files), `Ai/Tools/SearchKnowledgeToolTest`, `Ai/KnowledgeToolIntegrationTest`, `Ai/KnowledgePromptInjectionTest` (new this phase) | ✅ |
| Notifications | `Notifications/NotificationTriggersTest`, `Notifications/NotificationControllerTest` (new this phase) | ✅ |
| Audit-log generation & visibility | `Organisation/AuditLoggingTest` (new this phase); Activity/Communication/AgentInteraction/WorkflowApproval audit trails covered throughout their own domain suites | ✅ |
| Validation, authz failures, API failures, error handling | Present throughout every domain suite (e.g. `SendCommunicationJobTest`'s retryable/non-retryable failure paths, `WorkflowExecutionServiceTest`'s LLM-failure path, every `*AuthorizationTest`'s forbidden-access assertions) | ✅ |

## Results

```
Tests:    541 passed (1286 assertions)
Duration: ~15s (SQLite in-memory)
Pint:     passed, no style violations
```

- 88 test files, 541 tests, 0 failures, 0 skipped.
- 22 tests added in Phase 11 specifically (notifications: 9; audit
  logging: 5; security headers: 4; knowledge prompt injection: 4).
- Full suite re-run after every change in this phase, per CLAUDE.md's
  "run the relevant... full test suite before declaring work complete."

## What automated tests do **not** cover (honest limitations)

- **Live provider behavior.** No automated test has ever exercised a
  real Gmail send, WhatsApp Cloud API call, or Anthropic completion.
  This is by design (CLAUDE.md: "never require real credentials in
  automated tests") but means live-provider correctness is only
  verified manually — see `docs/UAT.md` for the scripted manual pass
  this phase requires before go-live, and each phase's own manual
  verification checklist docs where they exist (e.g.
  `tests/Manual/COMMUNICATIONS_MANUAL_VERIFICATION.md`).
- **Real LLM injection resistance.** `PromptInjectionTest`/
  `KnowledgePromptInjectionTest` prove the *system* prevents any effect
  even if a model complies with an injected instruction — they cannot
  and do not claim a real Claude model would refuse to comply
  linguistically. This is the correct, achievable guarantee for an
  automated suite (see each file's own docblock).
- **Load/performance testing.** Not performed this phase — see
  `docs/DEPLOYMENT.md`'s monitoring section for what to watch in
  production instead of a synthetic load test that wasn't run.
- **Multi-browser/visual UI testing.** Not in scope for this project's
  established testing approach (Feature tests assert HTTP responses/
  rendered content, not pixel-level rendering).
