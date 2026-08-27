# V1 Go/No-Go Checklist

Per Phase 11's required gate. Each row records what was actually
verified this phase and by what evidence — never checked off on
assumption.

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | Critical/high-severity defects resolved or explicitly accepted | ✅ Met | `docs/PHASE_11_AUDIT.md` §2 — all 4 defects found were fixed this phase; none deferred without recording why (§2.5's "not fixed" is a deliberate architecture decision, not a defect) |
| 2 | Required regression tests pass | ✅ Met | 541/541 passing, 0 failures (`docs/TEST_STRATEGY.md`) |
| 3 | Authorization and RLS tests pass | ✅ Met | `docs/SECURITY_REVIEW.md` §1–3; RLS verified directly against the real Supabase instance for the new `notifications` table this phase, and confirmed still `Ran`/enabled for every prior phase's tables |
| 4 | No known secret exposure or critical security issue | ✅ Met | `docs/SECURITY_REVIEW.md` §4 — `.env` never committed, no secret-pattern matches across full git history, `composer audit` clean |
| 5 | Prompt-injection and unauthorized-agent-action tests pass | ✅ Met | `docs/SECURITY_REVIEW.md` §5 — including new knowledge-layer-specific coverage added this phase |
| 6 | Backups and recovery steps documented **and validated** | ⚠️ **Partially met** | Documented in full (`docs/OPERATIONS.md` §1). **Not validated**: Supabase plan tier for production is unconfirmed, and no test restore has been performed. **This is the one open blocker below.** |
| 7 | Monitoring, logs, queue workers, schedulers, alerts ready | ⚠️ **Partially met** | Logging/audit trail ready and tested. Queue/scheduler configuration is documented and matches this codebase's actual job/command behavior (`docs/DEPLOYMENT.md` §4–6). **Not yet chosen**: an error-tracking/APM service, and named alert owners/thresholds — both explicitly flagged as user decisions needed |
| 8 | Production configuration, domain, HTTPS verified | ❌ **Not yet — no production environment exists** | Hosting platform, domain, and Supabase production project are all undecided (`docs/DEPLOYMENT.md` §1, §7) — this is expected at this stage, not a defect, but it means "verified" cannot yet be true |
| 9 | UAT signed off by product owner | ❌ **Not yet executed** | Scripts are written and ready (`docs/UAT.md`) but have not been run against a live staging environment — requires a staging environment with test Gmail/WhatsApp credentials, which does not yet exist |
| 10 | Rollback plan rehearsed or operationally credible | ✅ Met (credible, not yet rehearsed) | `docs/OPERATIONS.md` §4 — concrete, specific to this codebase's actual migration/queue/job behavior, not a generic template. Not literally rehearsed against a live deploy, because no deploy has occurred yet |

## Decision: **CONDITIONAL GO**

The V1 application itself — the code, its tests, its authorization
model, its AI-safety guarantees — is complete and passes every
criterion that can be verified without a live production environment
(#1–5, #10). It is **not** ready to actually launch yet, for reasons
that are entirely about environment/process readiness, not application
defects:

**Blocking items before a real GO:**

1. **Confirm the production Supabase plan tier and validate a test
   restore** (#6). This is the single most important remaining item —
   CLAUDE.md explicitly requires a *tested* recovery procedure, and a
   Free-tier project would have no usable backup at all.
2. **Choose the hosting platform, domain, and error-tracking service**
   (#7, #8) — three concrete decisions only the user/product owner can
   make; the deployment runbook is ready to execute once they're made.
3. **Stand up a staging environment and execute `docs/UAT.md`** (#9) —
   requires the above decisions first, plus a designated safe test
   Gmail account and WhatsApp test number (CLAUDE.md forbids using
   real customer channels for this).
4. **Assign alert ownership/escalation** (#7) — a name and SLA, not a
   technical task.

None of these require further application code changes. Once items
1–4 are resolved, re-run this checklist — expected outcome is GO,
assuming UAT passes and the test restore succeeds.

## What would make this NO-GO instead

- A test restore that fails or takes longer than an acceptable RTO
  (recovery time objective — not yet defined; **decision needed**:
  what RTO/RPO does the business actually require?).
- Any UAT section failing outright, particularly §5 (Gmail/WhatsApp
  send) or §9 (permission-denied scenarios) in `docs/UAT.md`.
- Discovery of a secret accidentally present in the chosen production
  hosting platform's configuration during setup (would need immediate
  rotation per CLAUDE.md's secrets-handling rule).
