# V2 Backlog

Items noted during Phase 1–11 as out of V1 scope, deliberately **not**
implemented. Nothing in this file has been scaffolded, designed in
detail, or started — recording an item here is not authorization to
begin it in a future session without the user's explicit go-ahead,
per CLAUDE.md's phase discipline.

## Explicitly out of scope per Phase 11's instruction

- **Cost-to-Serve Agent** — a fourth specialized AI agent, previously
  named by the user as planned post-V1. Not scaffolded, not designed,
  no code references it. Building it would require: a new
  `AgentIdentifier` case, a new `AgentDefinition`/prompt/tool
  permission matrix entry, and a routing rule in `AgentRouter` — all
  deliberately untouched.
- **Business Development Intelligence Agent** — the second
  previously-named future agent. Same status: not scaffolded, not
  designed.
- RAG / embeddings / vector databases — CLAUDE.md's V1 exclusion,
  reaffirmed by Phase 10's decision to use Postgres full-text search
  instead (`docs/KNOWLEDGE.md`). If a future phase revisits this, it
  requires the same explicit approval process Phase 10 went through.
- Obsidian integration.
- Social-media automation.
- External prospecting/enrichment systems.
- Any new AI-agent role beyond the existing three (Sales Intelligence,
  Performance & Management, Communication & Follow-Up).

## Other items noted during Phase 11's audit, not built

- **WhatsApp template-catalog / message-approval-status integration**
  — sending outside Meta's 24-hour customer-service window requires a
  pre-approved template; not implemented (`WhatsAppProvider::send()`'s
  own documented limitation, unchanged since Phase 6).
- **PDF/DOCX knowledge-document ingestion** — Phase 10 scoped knowledge
  uploads to plain text/Markdown only, to avoid adding a new
  Composer/system dependency without a separate, explicit decision.
- **General web rate limiting** beyond the specific endpoints already
  throttled (login, email verification, AI assistant) —
  `docs/SECURITY_REVIEW.md` §7 flags this as low-severity given the
  app has no public write endpoints, but a blanket `throttle` on
  authenticated write routes would be reasonable hardening.
- **A scheduled AI/provider spend rollup + alert** — `AgentInteraction.
  usage` already records token counts per call; a periodic summary
  command with an alert threshold was not built this phase
  (`docs/DEPLOYMENT.md` §6).
- **An extended `/up` readiness check** that verifies database
  connectivity, not just that the app booted — noted as a nice-to-have
  in `docs/DEPLOYMENT.md` §6, not built (would be new functionality
  beyond Phase 11's "complete/secure/test/monitor/deploy the current
  V1" remit).
- **Automated retention/pruning of `communications`/`agent_interactions`
  rows** — currently retained indefinitely; `docs/OPERATIONS.md` §3
  flags this as worth revisiting if storage growth becomes material.
- **A formal data-subject deletion ("right to be forgotten") workflow**
  — not required by any V1 spec seen so far and not built; would need
  its own scoped design if it becomes a real requirement
  (`docs/OPERATIONS.md` §3).
- **`composer.json`'s `minimum-stability: dev`** — inherited from the
  original Livewire starter-kit scaffold; tightening to `stable` is a
  separate, low-risk-but-not-zero-risk change worth doing deliberately
  rather than as an incidental Phase 11 edit (`docs/SECURITY_REVIEW.md`
  §8).
- **CI workflow cleanup** — `.github/workflows/lint.yml`'s Flux
  credential step is currently inert (no private-registry entry exists
  in `composer.json` to consume it); worth removing or actually wiring
  up correctly next time CI is touched (`docs/PHASE_11_AUDIT.md` §5).
