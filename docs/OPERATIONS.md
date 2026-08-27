# Operations — Backup, Recovery, Privacy, Retention, Rollback

## 1. Backup and recovery (Supabase)

**Not yet confirmed**: which Supabase plan tier the production project
will run on. Supabase's backup/PITR availability depends on plan:

| Plan | Backups | Point-in-time recovery |
|---|---|---|
| Free | none automatic | not available |
| Pro | daily automatic backups, 7-day retention | not included (add-on) |
| Team/Enterprise | daily backups, longer retention | available as an add-on |

**Decision needed from the user** before go-live: confirm the
production Supabase project's plan tier and whether PITR is enabled.
If the project is still on the Free tier, **this is a go/no-go
blocker** — CLAUDE.md requires backups/recovery to be documented *and
validated* before V1 approval, and Free tier has no recoverable backup
at all.

### Backup procedure (once plan is confirmed)

- **Automatic**: Supabase's own daily backup runs without app-side
  action once the plan supports it — confirm it's actually enabled in
  the project's Database → Backups settings.
- **Manual, pre-deployment**: trigger an on-demand backup (Supabase
  dashboard, or `pg_dump` via the connection string in an emergency)
  immediately before any deploy that includes a migration (see
  `docs/DEPLOYMENT.md` §3).

### Restore procedure

1. Identify the target restore point (a specific daily backup, or a
   PITR timestamp if available).
2. Restore via the Supabase dashboard's restore flow (or `pg_restore`
   from a manual `pg_dump`, for a self-managed backup).
3. **This creates a new database state — plan for a maintenance
   window.** A restore cannot be applied "live" without disrupting the
   app.
4. After restore: run `php artisan migrate:status` to confirm the
   restored database's migration state matches what the deployed code
   expects. If the restore point predates a migration the running code
   requires, that migration must be re-applied (`migrate --force`)
   before bringing the app back up.
5. Verify RLS state on a sample of tables (the same `pg_class` query
   used throughout this project's phase verifications) — a restore
   from a backup taken before RLS was enabled on a given table would
   restore it *without* RLS; re-run the relevant `_rls_and_constraints`
   migration if `migrate:status` doesn't show it as already applied
   against the restored database.

### Recovery procedure not yet tested against a real restore

**This is a go/no-go checklist item, not yet satisfied**: CLAUDE.md and
Phase 11 both require the recovery procedure to be *validated*, not
just documented. A test restore (into a separate, throwaway Supabase
project or branch, never overwriting the real dev/production database)
should be performed once the production plan tier is confirmed, before
V1 is approved for launch. See `docs/GO_NO_GO.md`.

## 2. Privacy practices

| Data category | Where it lives | Practice |
|---|---|---|
| CRM data (leads, contacts, opportunities) | Postgres, RLS-protected | Access restricted by role/team via Policies + RLS; never placed in a third-party system beyond the approved Gmail/WhatsApp/Anthropic integrations, each behind its own service boundary |
| Communication content (email/WhatsApp bodies) | `communications` table | Full message content retained for the audit/history requirement (CLAUDE.md: "communications are records of actual events"); never included in `AgentInteraction` audit rows beyond a redacted `[redacted]` placeholder for draft body/subject |
| Knowledge records | `knowledge_document_versions`/`knowledge_chunks` | Company-authored policy/SOP content, not customer PII by design; access filtered by the same visibility model as everything else |
| Logs | `storage/logs/*.log` | Never contain tokens, passwords, or secrets (verified by convention throughout — no `Log::` call anywhere in the codebase logs a credential); the audit channel (Phase 11) logs only actor/target ids and role/team values, never message content |
| AI prompts | Sent to Anthropic per-call, not persisted beyond `AgentInteraction`'s own truncated (4000-char), redacted request/response fields | System prompts are never logged at all (by design, since Phase 7); only the user's own message and the model's final text are recorded, both truncated |

**Minimization**: every `AgentTool` returns only the curated fields
needed to answer, never a raw Eloquent model (established convention,
re-verified for the knowledge layer in Phase 10/11's own review).

## 3. Retention and deletion

| Data | Retention | Deletion |
|---|---|---|
| CRM records | Indefinite (business records) | Soft-delete/archive behavior is defined per entity where applicable (e.g. `RecordStatus`); hard deletion is a Manager-authorized action where offered (e.g. `KnowledgeDocument::delete()` cascades to its versions/chunks) |
| Communications | Indefinite (audit requirement) | No automated deletion; a future retention policy (e.g. "archive communications older than N years") is a V2 decision, not built |
| Agent interactions | Indefinite currently | Same — no automated pruning built; consider a scheduled prune job in V2 if audit-storage growth becomes a concern |
| Audit log (`storage/logs/audit.log`) | `LOG_AUDIT_RETENTION_DAYS`, default 365 days (Phase 11, `config/logging.php`) | Automatic, via Monolog's daily-rotation `days` setting |
| General application log | Platform-dependent (not yet configured with an explicit retention policy beyond the `daily` driver's own `days` setting) | Set `LOG_DAILY_DAYS` explicitly for production; not yet set |
| Demo/seed data | Development/test environments only | `DatabaseSeeder` (Phase 11) now refuses to run in a production environment unless `ALLOW_DEMO_SEED_IN_PRODUCTION=true` is explicitly set — fail-closed by default |

**No formal data-subject deletion request (e.g. GDPR "right to be
forgotten") workflow exists.** Not required by any V1 spec seen so
far, and not built — recorded here honestly rather than silently
assumed handled. If this becomes a real requirement, it needs its own
scoped design (which records can be deleted vs. must be retained for
audit, and how CRM/communication/audit-log data intersect) — V2
backlog item.

## 4. Rollback plan

### 4.1 Application rollback

```bash
php artisan down --render="errors::503" --retry=60
git checkout <previous-release-tag-or-commit>
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan config:clear && php artisan config:cache
php artisan queue:restart
php artisan up
```

### 4.2 Database migration rollback

- **Safe to roll back** (via `php artisan migrate:rollback`) only for
  migrations that are purely additive and reversible — every migration
  in this project defines a `down()` method (verified across all 10
  phases), so mechanical rollback is always *possible*.
- **Unsafe to roll back, restore instead**, when:
  - Data has already been written to a column/table the rollback would
    drop (e.g. any `knowledge_documents`/`notifications` row created
    after the migration ran) — `down()` would silently destroy that
    data.
  - The rollback would re-disable RLS on a table that has been
    receiving production traffic (a window, however brief, with RLS
    off is a real exposure).
  - More than one release has shipped since the migration in question
    — rolling back N migrations to undo one release risks undoing
    unrelated, still-wanted schema changes from releases in between.
- **Decision point**: if the failed release only added new,
  not-yet-used tables/columns (no real user data written to them yet),
  `migrate:rollback` is reasonable. If real data exists in the new
  structures, prefer restoring from the pre-deploy backup (§1) over a
  destructive rollback, and treat the rolled-back-to release's queue/
  scheduler state as needing the same verification as a fresh restore
  (§1's post-restore checklist).

### 4.3 Queue/job handling during rollback

- `php artisan queue:restart` before bringing workers back up, so no
  in-flight worker process executes old, now-rolled-back job code
  against new (or reverted) data.
- Check `failed_jobs` before and after — a rollback should not be
  performed while jobs tied to the *new* release are still queued and
  unprocessed; drain or explicitly discard them first (`php artisan
  queue:flush` only after confirming nothing important is queued —
  this is destructive).

### 4.4 Communication and AI-job containment

- No autonomous send path exists anywhere in this application (every
  phase's tests prove this), so a rollback carries no risk of "an
  in-flight AI action sends something unexpected" — the worst case is
  a queued `SendCommunicationJob` for an already-human-approved message
  processing against reverted code, which is safe since that job's own
  logic (idempotency guard, retry/backoff) is unchanged by any rollback
  scenario likely in this app's near-term release cadence.
- If a rollback is specifically because of an AI/agent defect
  (a bad draft, a workflow misbehaving), also disable the relevant
  `WORKFLOW_*_ENABLED` flag in `.env` immediately — faster and safer
  than waiting for a full application rollback to take effect.

### 4.5 Post-rollback verification

Same checklist as `docs/DEPLOYMENT.md` §9 (post-deploy verification),
run again after any rollback.

### 4.6 Incident record and follow-up

Every rollback should be recorded (even informally, e.g. a dated entry
in an incident log or issue tracker — not built as in-app functionality
this phase, since that would be new product scope beyond Phase 11's
remit) with: what triggered it, what was rolled back to, what data (if
any) was lost or required a restore instead of a mechanical migration
rollback, and the follow-up fix required before the next deploy
attempt.
