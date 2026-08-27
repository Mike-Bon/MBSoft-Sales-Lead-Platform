# Phase 11 — Repository & Architecture Audit

This document records what was actually found in the repository at the
start of Phase 11 (V1 Completion, Hardening, Testing, and Production
Deployment), distinguishing **existing implemented features**,
**defects found and fixed**, **improvements made**, and **future/V2
backlog items** (see `docs/V2_BACKLOG.md` for the last of these in
full).

## 1. What exists (Phases 1–10, as built)

| Phase | Scope | State at Phase 11 start |
|---|---|---|
| 1 | Laravel 12 + Livewire starter kit, Supabase-ready config, auth shell | Complete |
| 2 | Users, roles (Manager/Team Head/Team Member), teams, policies, RLS | Complete |
| 3 | CRM: organizations, contacts, leads, opportunities, activities, assignment | Complete |
| 4 | Targets & performance calculation engine | Complete |
| 5 | Role-aware dashboards | Complete |
| 6 | Gmail + WhatsApp Business Platform integration, `Communication` audit model | Complete |
| 7 | Single constrained AI agent (`Agent`/`AgentTool`/`ToolRegistry`), audited via `AgentInteraction` | Complete |
| 8 | Controlled agentic workflows (3 scheduled workflows → deterministic analyzer → agent → `WorkflowApproval`) | Complete |
| 9 | Specialized 3-agent architecture (Sales/Performance/Communication), routing, one cross-agent orchestrator | Complete |
| 10 | Knowledge layer — Postgres full-text search (not embeddings/pgvector, by deliberate, documented decision) | Complete |

Before Phase 11, the automated suite stood at **519 passing tests**
across all ten phases. Architecture is consistent throughout: thin
controllers, business logic in services, enums instead of magic
strings, Form Requests for validation/authorization, Policies for
record-level authorization, RLS (`ENABLE` + `FORCE`, no policies —
true default-deny for any non-`BYPASSRLS` role) on every table, and
strict provider isolation for every external integration (Gmail,
WhatsApp, the LLM). No autonomous send path exists anywhere — every
outbound message requires an explicit human confirmation, verified by
dedicated tests in every phase that touches communication.

## 2. Defects and gaps found, and their resolution

### 2.1 Notifications never built (CLAUDE.md V1 scope gap)

CLAUDE.md's V1 core capabilities list names "notifications" alongside
queue-backed background work. No phase 1–10 spec built one — no
`app/Notifications`, no `notifications` table, no UI. Confirmed with
the product owner (this phase) that this should be closed now rather
than deferred.

**Fixed:** a minimal, narrowly-scoped in-app notification system —
Laravel's standard `notifications` table (RLS enabled/forced, same
default-deny pattern as every other table), two `Notification` classes
(`WorkflowApprovalPendingNotification`, `CommunicationFailedNotification`),
database channel only (no email/SMS side-channel — consistent with "no
autonomous outbound messaging"), a `/notifications` index page, and a
bell/badge in the header nav. Wired into exactly two existing trigger
points (`WorkflowExecutionService::createApproval()`,
`SendCommunicationJob::markFailed()`) — no new send path, no new
external integration. 18 new tests.

### 2.2 Role/team/leadership-change audit logging deferred

`UserManagementService` and `TeamManagementService` had explicit
`// Audit-log hook point` comments at four call sites
(`createUser`, `updateUserRoleAndTeam`, `createTeam`,
`assignTeamHead`), left unfilled since Phase 2. CLAUDE.md requires
logging "role/team/ownership changes" as a security-relevant event.

**Fixed:** a new `App\Support\AuditLogger::record()` helper writing to
a dedicated `audit` log channel (`config/logging.php`, daily-rotated,
configurable retention via `LOG_AUDIT_RETENTION_DAYS`, default 365
days), called from all four hook points with actor id/role, target,
and before/after values — never a password, token, or other secret.
`updateTeam` (name/code/status) deliberately remains unlogged, matching
the original scoping: CLAUDE.md's audit list doesn't name those fields,
only role/team/ownership/leadership. 5 new tests assert the exact log
entries (and the deliberate non-entry for `updateTeam`).

### 2.3 No production-hardening middleware

No `TrustProxies`/`TrustHosts` configuration existed, and no baseline
security response headers were set. Both matter specifically for
production (correct HTTPS/client-IP detection behind a load balancer;
defense-in-depth headers), not for the CRM's core functionality, which
is why this wasn't caught by any functional test in Phases 1–10.

**Fixed:** `App\Http\Middleware\SecurityHeaders` (X-Content-Type-Options,
X-Frame-Options, Referrer-Policy, Permissions-Policy, and HSTS when the
request is actually HTTPS) appended to the `web` middleware group;
`trustProxies()` wired to a new `TRUSTED_PROXIES` env var, defaulting to
trusting nothing (safe for local/testing) — see `docs/DEPLOYMENT.md`
for the required production value. 4 new tests.

### 2.4 Dead code: unrouted self-registration view

`resources/views/livewire/auth/register.blade.php` (a Livewire Volt
component) has existed, unrouted, since self-registration was
intentionally disabled in Phase 2 (`routes/auth.php`'s own comment
documents why: this app has a managed organisational structure, every
account is created by the Manager with an explicit role/team).
`RegistrationTest` already locks in that `/register` doesn't exist and
never referenced the view file directly.

**Fixed:** removed the file. No route, test, or other view referenced
it; `RegistrationTest` still passes unmodified.

### 2.5 No dedicated `AuditLog` table

Considered and deliberately **not** built as a new table. The
project's established pattern already produces an audit trail across
several purpose-built tables — `Activity` (CRM timeline facts,
including ownership reassignment — see 2.6), `Communication` (every
outbound/inbound message with full status history), `AgentInteraction`
(every AI tool call, redacted where needed), `WorkflowExecution`/
`WorkflowApproval` (every proactive AI action and its human decision) —
plus, as of 2.2, the new `audit` log channel for organisational
changes. Adding a single generic `AuditLog` table on top would
duplicate this without adding coverage, and risks becoming the "one
audit table nobody actually queries" anti-pattern. This is documented
here as the audit *architecture*, not left implicit.

### 2.6 CRM record reassignment — already correctly audited (no gap)

Checked specifically because CLAUDE.md calls out "reassignment must be
... visible in the audit trail." `LeadService::update()` already
writes an `Activity` record when `owner_id` changes (comparing
`$previousOwnerId` before/after). No fix needed.

## 3. Improvements made (beyond defect fixes)

- `SearchKnowledgeTool` and knowledge-layer prompt-injection coverage
  extended with a dedicated test file (`KnowledgePromptInjectionTest`,
  4 tests) — Phase 10 predates `PromptInjectionTest` and had no
  injection/exfiltration coverage of its own for the knowledge layer
  specifically.
- `.env.example` gained a documented "Production hardening" section
  (`TRUSTED_PROXIES`, `LOG_AUDIT_RETENTION_DAYS`) and an explicit
  `SESSION_SECURE_COOKIE` placeholder with production guidance.

## 4. Confirmed sound on inspection (no action needed)

- **Pagination**: every CRM/communication/target/activity list
  controller already paginates (`->paginate(...)`) — no unbounded-list
  risk found.
- **Scheduler**: `workflows:run-daily` uses `withoutOverlapping()` and
  `onOneServer()` — overlap-safe already.
- **Login rate limiting**: the Volt login component has its own
  `RateLimiter` (5 attempts, IP+email-keyed lockout) — not a gap.
- **CSRF**: only the WhatsApp webhook is exempted, with its own HMAC
  signature verification as the substitute authentication — correct
  and narrowly scoped.
- **Route protection**: every route group requires `['auth',
  'verified']` — verified by direct inspection of all nine route
  files.
- **RLS**: every table created in Phases 2–11 has `ENABLE`+`FORCE ROW
  LEVEL SECURITY` with zero policies (true default-deny for any
  non-`BYPASSRLS` role) — verified directly against the real Supabase
  instance for every phase's tables, including this phase's new
  `notifications` table.

## 5. Known, deliberate V1 limitations (not defects)

- **WhatsApp template-catalog/approval-status integration** is not
  implemented (`WhatsAppProvider::send()`'s own docblock) — sending
  outside Meta's 24-hour customer-service window requires a
  pre-approved template, not implemented in V1. Documented, not hidden.
  V2 backlog item.
- **Knowledge search is keyword-only, not semantic** — the deliberate,
  approved substitution for embeddings/RAG (`docs/KNOWLEDGE.md`).
- **Knowledge ingestion supports plain text/Markdown only** — no
  PDF/DOCX (would require a new Composer dependency, a separate
  decision).
- **CI workflows** (`.github/workflows/lint.yml`,`tests.yml`) are
  unmodified Livewire-starter-kit templates. `lint.yml`'s Flux
  credential step is currently inert — `composer.json` has no
  `repositories.flux` entry requiring those credentials at all, so the
  step neither fails nor does anything meaningful. Left as-is (CI
  configuration/licensing setup is outside Phase 11's narrow-defect
  scope); flagged here for whoever next touches CI.
- **`minimum-stability: dev`** in `composer.json` is the Livewire
  starter kit's own default, inherited unmodified since Phase 1. Not
  changed in Phase 11 (touching the dependency-resolution strategy is
  a higher-risk, separate decision) — flagged in
  `docs/SECURITY_REVIEW.md`'s dependency section.

## 6. Files changed in Phase 11

See the Phase 11 commit for the full list. Summary by category:
notifications (migration, 2 notification classes, controller, routes,
2 views, header nav), audit logging (`AuditLogger`, 2 service files, 2
controller call sites, logging config), security hardening
(`SecurityHeaders` middleware, `bootstrap/app.php`, `.env.example`),
one deletion (dead registration view), and 27 new/extended test files
across `tests/Feature/Notifications/`, `tests/Feature/Organisation/`,
`tests/Feature/SecurityHeadersTest.php`, and
`tests/Feature/Ai/KnowledgePromptInjectionTest.php`.
