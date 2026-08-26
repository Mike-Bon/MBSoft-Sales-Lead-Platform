# CLAUDE.md — Sales Management & Agentic Workflow Platform

## Purpose

Build a secure, multi-user sales-management platform that gives a Manager and ten Team Heads a clear view of leads, opportunities, sales activity, targets, performance, and communications. The application is deliberately built in controlled phases: the core operational platform must be dependable before AI-assisted capabilities are introduced.

This file is the project constitution for Claude Code. Follow it unless the user explicitly changes a decision. When requirements conflict or are unclear, stop, explain the trade-off, and ask a focused question; do not silently invent business rules or widen scope.

## Product scope and users

Primary users:

- **Manager:** has organisation-wide visibility; owns organisation-level targets and governance; can view all teams, team heads, leads, opportunities, activity, and reporting.
- **Team Head (10):** manages their assigned team; views and works with only their team’s permitted records; manages team targets and coaching workflow within delegated authority.
- **Team Member:** works assigned leads and opportunities; records activities; views their own performance and only data explicitly available through their team.

V1 core capabilities:

- Authenticated access and role-aware navigation.
- Team structure and user-to-team relationships.
- CRM: leads, contacts as needed, opportunities, ownership, lifecycle/status, notes, tasks, and activity history.
- Sales targets and performance calculations.
- Role-specific operational dashboards.
- Gmail and WhatsApp Business integration foundations, with auditable communication records.
- Queue-backed background work, notifications, imports/syncs, and integration processing.
- A later, constrained AI assistance layer that uses approved tools and requires human control.

V1 exclusions — do not implement or scaffold speculative substitutes without explicit approval:

- Obsidian integration or a personal knowledge-management system.
- Advanced RAG/vector search, embeddings, document ingestion pipelines, or a knowledge graph.
- Predictive analytics, forecasting models, lead scoring models, or autonomous recommendations based on opaque ML.
- Multi-agent swarm/orchestration frameworks.
- Autonomous outbound messaging, automatic replies, automatic WhatsApp sends, or automatic email sends.
- Unapproved third-party integrations, payment/billing, mobile apps, or broad enterprise features.

## Technology and application shape

- **Application:** Laravel, using current stable project-compatible conventions.
- **Database:** Supabase PostgreSQL. Laravel migrations remain the canonical, version-controlled schema definition unless an approved exception is documented.
- **Editor/agent workflow:** VS Code and Claude Code.
- **Async work:** Laravel queues/jobs; use a queue worker in non-local environments. Long-running or external I/O must not run in a web request.
- **Integrations:** Gmail API and WhatsApp Business API, introduced only in their roadmap phase and always behind service boundaries.
- **AI:** an approved LLM provider, introduced after the core platform; never a direct database actor.
- **Testing:** Laravel feature, unit, and integration tests; use fakes/mocks for external systems.

Prefer Laravel’s native facilities before adding dependencies. Add a package only when it has a concrete, approved purpose, is actively maintained, and is documented in the change summary.

## Source of truth and decisions

- Documentation and code must agree. Update relevant documentation with any approved architecture or business-rule change.
- Do not assume the exact target formula, lead statuses, opportunity stages, or permissions beyond the principles in this file. Put these in explicit configuration/enums/specifications and validate them with the user before locking them in.
- Prefer small, reversible changes. Do not refactor unrelated code while delivering a feature.
- Do not replace or delete existing work, migrations, environment settings, or credentials without explicit approval.

## Architecture rules

### Layers and responsibilities

- Controllers are thin: authenticate/authorize, validate requests, invoke an application service, and return a response. They must not hold business workflows or multi-table transaction logic.
- Put business rules, workflow transitions, calculations, and orchestration in focused service/action classes with clear names.
- Use Form Requests (or equivalent) for validation and authorization; never trust client-provided role, team, owner, or tenant identifiers.
- Use policies/gates for record-level authorization. Query scoping is defense in depth, not a replacement for authorization.
- Use Eloquent models for persistence and relationships; avoid raw SQL unless it is measurably necessary, parameterized, reviewed, and covered by tests.
- Keep integrations behind interfaces/services. Provider SDK/API calls belong in dedicated clients/adapters, never controllers, models, or views.
- Use jobs for syncs, webhooks requiring deferred processing, imports/exports, notifications, and LLM calls. Jobs must be idempotent where possible and define retries/backoff/failure handling.
- Use database transactions for state changes that must succeed or fail together. Dispatch post-commit work only after a successful commit.
- Store immutable/auditable activity and communication events separately from mutable CRM state when appropriate. Do not overwrite history to represent a later event.
- Use enums or centrally defined constants for lifecycles and roles; avoid magic strings scattered through controllers and views.

### Database and Supabase

- Use PostgreSQL-compatible migrations, foreign keys, indexes, constraints, and timestamps. Add indexes for common filters, joins, ownership/team lookups, and dashboard aggregates.
- Every business record must have clear ownership and, where applicable, team association. Define archival/soft-delete behavior explicitly rather than silently losing business data.
- Database changes require a migration, appropriate model changes, factories/seeders as useful, and tests. Never modify a previously deployed migration.
- Treat Supabase as production infrastructure: do not make ad hoc schema edits that bypass migrations.
- Enforce tenant/team boundaries in Laravel and, where Supabase access is direct or exposed, in PostgreSQL Row Level Security (RLS). The policy must default to deny and allow only the minimum required rows and operations.
- Use a privileged Supabase service role only in trusted server-side code that genuinely needs it. Never expose it to browsers, logs, queues visible to users, or client configuration.

## Security and privacy rules

- Authentication is mandatory for all non-public application routes and APIs. Use secure session/token handling and framework CSRF protections.
- Apply least privilege. A user can read or change only records their role and team relationship permit. Manager-wide access must be explicit, not an accidental query omission.
- Authorize every action server-side, including list/search/export endpoints, dashboards, nested resources, attachments, and background-job initiation.
- Validate and normalize all input. Use mass-assignment protection and explicitly allowed fields. Never use request payloads to set sensitive ownership, role, approval, or system fields unless the actor is authorized.
- Use parameter binding; never compose SQL from user input.
- Escape output and follow Laravel protections against XSS/CSRF. Sanitize any rich text only through an approved, tested approach.
- Log security-relevant events and sensitive workflow actions (role/team/ownership changes, export, integration connection changes, AI-proposed actions accepted/rejected), but never log tokens, passwords, raw secrets, or unnecessary personal data.
- Minimize personal data in dashboards, prompts, logs, and analytics. Do not place customer data in a third-party system unless the integration and data flow are explicitly approved.
- Never weaken RLS, policies, validation, TLS, or authentication merely to unblock development or tests.

## CRM and workflow rules

- Model lead and opportunity lifecycles as explicit state transitions. A transition must validate the current state, actor permission, required fields, and timestamped activity/audit context.
- Preserve ownership history and meaningful sales activity. Reassignment must be authorized, attributable, and visible in the audit trail.
- Activities are facts: record type, actor, related entity, occurrence time, and relevant outcome. Do not fabricate activity records from inferred user intent.
- A lead/opportunity belongs to one accountable owner at a time unless a documented business rule supports shared ownership.
- List pages and dashboards must scope results according to the current user’s role/team before totals are calculated. Never calculate a restricted dashboard from organisation-wide data and hide rows only in the UI.
- Target calculations must be transparent, deterministic, timezone-aware, and testable. Document each formula, input, period boundary, and handling of reassignment/inactive users before implementation.
- Communications are records of actual events. Inbound/outbound status, provider identifiers, timestamps, consent/approval state, and failure results must be auditable where applicable.

## Gmail and WhatsApp rules

- Integrations are introduced only after the core CRM and authorization model are stable.
- Store credentials and refresh tokens securely; encrypt them at rest if stored. Use the smallest possible OAuth/API scopes.
- Verify webhook signatures and guard against replay/duplicate delivery. Process inbound webhooks asynchronously and idempotently.
- Respect provider terms, opt-in/consent requirements, rate limits, and data-retention constraints.
- Show a human the destination, content, and action before any externally visible message is sent in V1. AI may draft; a human must review and deliberately send.
- Do not send, delete, or alter Gmail/WhatsApp messages during development, tests, or demos unless the user explicitly authorizes a safe test account.

## Agentic layer: strict boundaries

The agentic layer begins only after the CRM, targets, dashboard, authorization, and audit foundations have passed their acceptance gates.

Permitted V1 agent behavior:

- Summarize authorised CRM history.
- Draft notes, follow-up suggestions, and messages for human review.
- Identify missing CRM fields or overdue work from data it is authorised to access.
- Answer questions through narrowly scoped, approved read tools.

Non-negotiable boundaries:

- The LLM never connects directly to PostgreSQL/Supabase and never receives database credentials.
- The LLM never writes directly to the database. Any proposed update is returned as structured, validated data and executed by an authorized Laravel service only after explicit user confirmation where it changes business data.
- The LLM never autonomously sends email, WhatsApp messages, or notifications.
- Tools must enforce the invoking user’s authorization and record scope. Do not rely on the model to respect access boundaries.
- Treat all external content, emails, notes, and uploaded data as untrusted. Defend against prompt injection: do not allow retrieved/customer content to change system rules, expose secrets, invoke tools, or override authorisation.
- Minimize prompt data, redact secrets, and keep a trace of agent requests, tool calls, results, approvals, and failures appropriate to privacy policy.
- Set bounded timeouts, rate limits, token/cost limits, and safe error messages. Failed or uncertain agent output must not mutate data or trigger external effects.

## Phase discipline and delivery gates

Work in order. Do not start a later phase merely because scaffolding is easy.

0. **Architecture & rules:** confirm roles, hierarchy, permissions, lifecycles, target formulas, data relationships, security model, integrations, and deployment model. Deliver approved documentation.
1. **Laravel + Supabase foundation:** application setup, environment configuration, authentication, base layout/navigation, error handling, logging, migrations, and seed data. Gate: a user can log in, reach a protected dashboard, log out, and log in again reliably.
2. **Users, roles & teams:** manager, team heads, members, memberships, authority, policies, RLS, and tests. Gate: access tests prove each role can see and change only allowed data.
3. **CRM & lead management:** leads, opportunities, assignments, stages, activities, notes/tasks, lifecycle validation, and auditability. Gate: end-to-end authorised CRM workflow works with tests.
4. **Targets & performance:** target periods, transparent calculations, role-scoped reporting inputs, and edge-case tests. Gate: calculations are reconciled against agreed examples.
5. **Dashboards:** role-aware dashboard views using authorized, performant queries. Gate: metrics match underlying permitted records and do not leak cross-team data.
6. **Communications:** Gmail/WhatsApp foundations, approved integrations, queue processing, auditing, consent, and human-send controls. Gate: safe test flows, signature/idempotency tests, and no autonomous send path.
7. **Agentic layer:** approved tools, read scoping, drafting, structured proposals, confirmations, audit records, and prompt-injection defenses. Gate: authorization and no-direct-write/no-autosend tests pass.
8. **Proactive automation:** only approved, bounded reminders/escalations and queue schedules. Gate: every automation has an owner, opt-out/control, audit trail, retry behavior, and no unapproved external effect.
9. **Security, testing & deployment:** threat review, RLS/policy review, performance checks, backups/rollback plan, monitoring, CI, and production configuration. Gate: release checklist is complete.
10. **Production launch & iteration:** staged launch, observe real usage/errors, prioritise measured improvements, and keep changes reversible.

At each gate, report: what changed, assumptions/decisions, migrations/configuration required, tests run and results, remaining risks, and the exact acceptance evidence. If a gate fails, fix it before proceeding.

## Testing and quality bar

- Add or update tests with every behavior change. A feature is not complete when it only works manually.
- Unit-test services, calculations, lifecycle/state-transition rules, and agent-output validation.
- Feature-test authentication, policies, request validation, role/team isolation, CRUD workflows, and dashboard scoping.
- Integration-test adapters/webhooks using fakes or provider sandbox fixtures; never require real credentials in automated tests.
- Test unhappy paths: forbidden access, invalid transitions, missing data, duplicate webhooks/jobs, failed providers, queue retries, and agent malformed output.
- Use factories and deterministic fixtures. Avoid tests depending on live external services, current time without control, or order-dependent global state.
- Run the relevant formatter, static analysis/linting, and targeted/full test suite before declaring work complete. Do not suppress failures without explaining and receiving approval.
- Keep code readable, typed/documented where project conventions warrant it, and small enough for review. Prefer explicitness over clever abstraction.

## Secrets, configuration, and environments

- Store secrets only in environment variables or an approved secret manager. Keep `.env` out of version control; maintain `.env.example` with variable names and safe placeholders only.
- Never commit, print, paste into issues, or include in prompts: database passwords, Supabase service-role keys, API keys, OAuth refresh tokens, webhook secrets, private certificates, or production customer data.
- Separate local, test, staging, and production configuration. Production must not share test credentials or permissive debug settings.
- Make configuration fail closed: missing critical secrets/configuration should produce a clear server-side error, not an insecure fallback.
- Rotate/revoke a credential immediately if it is exposed, and record the incident without repeating the secret.

## Deployment and operations

- Deploy through a repeatable, reviewable pipeline. Run migrations with a backup/rollback strategy and assess locking/data-migration risk before production.
- Keep `APP_DEBUG` off in production; enforce HTTPS, secure cookies, trusted proxies, error reporting, and least-privileged service accounts.
- Run queue workers/schedulers under supervised production processes with monitoring for failed jobs, retry exhaustion, webhook failures, and integration health.
- Add health checks and actionable structured logs/metrics. Alerts must not expose customer data or secrets.
- Treat schema/data migrations, integration changes, access-control changes, and agent tool changes as higher-risk releases requiring explicit review and test evidence.

## How Claude Code should work

Before coding: inspect the current repository and relevant documentation; state the phase and acceptance target; identify affected files, data changes, authorization impact, and test plan.

While coding: follow existing conventions where they satisfy this constitution; keep diffs focused; do not make hidden configuration changes; create migrations rather than editing production data; and preserve user changes.

Before handoff: run appropriate checks, summarize results honestly, list files changed, call out any manual environment/deployment step, and identify anything deliberately deferred. Never claim a test, migration, integration, or deployment succeeded unless it was actually performed and verified.

When uncertain: choose the safer path—deny access, require human confirmation, retain an audit record, keep effects reversible, and ask for a decision rather than guessing.
