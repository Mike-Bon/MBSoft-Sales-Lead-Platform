# Production Deployment Runbook

This is a **runbook and checklist**, not a record of anything already
deployed. No production infrastructure has been provisioned and no
irreversible action has been taken — per Phase 11's explicit
instruction, deployment requires the user's separate, explicit
approval before any of the steps below are actually executed.

**Hosting platform is not yet decided.** This runbook is written to be
correct for any standard Linux host running PHP-FPM + a web server
(nginx/Apache) + Supabase Postgres — whether that's a managed platform
(e.g. Laravel Forge, Vapor) or a plain VPS. Commands below assume a
traditional VPS with `supervisor` and `cron`; substitute the
platform-native equivalent (e.g. Forge's own queue/scheduler UI) if one
is chosen instead. **Decision needed from the user before this runbook
can be executed**: which hosting platform.

## 1. Build and deployment workflow

```bash
# On the deployment target, as the deploy user:
git pull origin main                      # or your release tag/branch
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan down --render="errors::503" --retry=60   # maintenance mode

# Pre-deployment backup — see §3 before proceeding past this line.

php artisan migrate --force                # see §2 for safety checks first

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

sudo supervisorctl restart laravel-worker:*   # see §4
php artisan queue:restart                     # ensures workers pick up new code even without a supervisor restart

php artisan up
```

`config:cache`/`route:cache`/`view:cache` are safe here because this
app's configuration is fully environment-variable-driven (no
`env()` calls outside `config/*.php`) — confirmed by the existing
convention used throughout every phase. Do **not** run `config:cache`
in any environment where that isn't true.

## 2. Migration procedure

1. **Never** edit a previously-deployed migration (CLAUDE.md rule,
   held throughout all 10 phases so far).
2. Before running `migrate --force` in production:
   - Take a fresh Supabase backup/snapshot (§3).
   - Run `php artisan migrate --pretend` against production to review
     the exact SQL that will execute.
   - Check for locking risk: this app's migrations to date are all
     additive (`CREATE TABLE`, `ADD COLUMN` nullable, `ADD CONSTRAINT`,
     RLS enable/force) — none has altered or dropped an existing
     column in place. If a future migration ever needs to, treat it as
     a higher-risk release requiring its own reviewed plan (CLAUDE.md's
     "Deployment and operations" section).
3. Run `php artisan migrate --force` (the `--force` flag is required
   in production since `APP_ENV=production` disables interactive
   confirmation).
4. Verify: `php artisan migrate:status` shows every migration `Ran`,
   and spot-check RLS state the same way this phase did:
   ```sql
   select relname, relrowsecurity, relforcerowsecurity
   from pg_class
   where relname in ('notifications', 'knowledge_documents', /* ... */);
   ```

## 3. Backup before every deploy that migrates

See `docs/OPERATIONS.md` §1 for the full backup/restore/PITR
procedure. Minimum bar for every deploy that includes a migration: a
verified-recent Supabase backup (automatic daily backup, or a manual
one triggered immediately before the deploy) exists and its restore
procedure has been tested at least once (§7 of this checklist tracks
that as a go/no-go item).

## 4. Queue worker configuration

`QUEUE_CONNECTION=database` (current default). A `supervisor` config
(`/etc/supervisor/conf.d/laravel-worker.conf`):

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/app/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/app/storage/logs/worker.log
stopwaitsecs=3600
```

`--tries=3` matches the retry counts already coded into
`SendCommunicationJob`/`ProcessKnowledgeDocumentVersionJob` (both set
their own `$tries`/`$backoff` — the CLI flag is a floor, not an
override, for any job that doesn't set its own). `--max-time=3600`
recycles workers hourly to bound memory growth. Two processes gives
basic redundancy for this app's expected volume (10 team heads +
manager, not high-throughput).

**Failed jobs**: `failed_jobs` table (Laravel default, via the
`database` queue driver's failed-job handling) — monitor with
`php artisan queue:failed` and alert on any row present (§6).

## 5. Scheduler configuration

Single cron entry (standard Laravel):

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The only scheduled task today is `workflows:run-daily`
(`routes/console.php`), `dailyAt(config('services.workflows.run_at'))`
(default `08:00`), `withoutOverlapping()` + `onOneServer()`. **Set
`WORKFLOW_RUN_AT` in production `.env` accounting for the server's
configured timezone** (`APP_TIMEZONE`, not set explicitly anywhere yet
— defaults to UTC; confirm this is the intended interpretation of
"08:00" before go-live, or set `APP_TIMEZONE` explicitly — this is a
go/no-go checklist item, §UAT/GO-NO-GO).

## 6. Monitoring

No APM/error-tracking service is currently wired in. **Decision needed
from the user**: which service (e.g. Sentry, Flare, Bugsnag) for
error tracking. Until one is chosen, the minimum viable monitoring is:

- **Application errors**: `storage/logs/laravel.log` (or your chosen
  `LOG_CHANNEL`) — ship to a log aggregator or at minimum alert on any
  `ERROR`/`CRITICAL` line via a simple `tail -F | grep` cron+mail, or
  the platform's native log alerting if using a managed host.
- **Audit log**: `storage/logs/audit.log` (dedicated channel, Phase
  11) — retained `LOG_AUDIT_RETENTION_DAYS` (default 365) days.
- **Queue failures**: alert on any row in `failed_jobs`
  (`php artisan queue:failed` in a cron health check, or a scheduled
  command that emails/Slacks a count > 0).
- **Scheduler health**: `workflows:run-daily` should produce a
  `WorkflowExecution` row (or a clean "no findings" one) for every
  enabled workflow, every enabled user, every day — a daily check
  comparing expected vs. actual row count is a cheap, effective
  scheduler-health signal, since `withoutOverlapping()` silently
  skipping a run (e.g. because a prior run hung) would otherwise go
  unnoticed.
- **Integration failures**: `Communication.status = failed` rows and
  the new `CommunicationFailedNotification` (Phase 11) already surface
  these to the sending user; for ops visibility, alert on a failure
  *rate* spike (e.g. >10% of sends failing in an hour) rather than any
  single failure.
- **Database issues**: Supabase's own dashboard/alerts (connection
  count, CPU, disk) — confirm alerting is enabled in the Supabase
  project settings before go-live.
- **AI/provider spend**: `AgentInteraction.usage` (input/output token
  counts) is recorded per call already — a daily/weekly rollup query
  against this table is the cheapest way to watch spend without adding
  a new integration. No automated alert exists yet; recommended as a
  simple scheduled command in a near-term follow-up (V2 backlog).
- **Health checks**: `/up` (Laravel 12's built-in health-check route,
  already configured in `bootstrap/app.php`) — point your uptime
  monitor at it. It only proves the app booted, not that the database
  is reachable; consider extending it (a small custom check hitting
  `DB::connection()->getPdo()`) if deeper readiness checking is wanted
  — not built this phase (would be new functionality beyond Phase 11's
  scope of "complete, secure, test, monitor, and deploy the current
  V1"), noted for V2.

**Alert thresholds and ownership**: not yet assigned — **decision
needed from the user**: who is the on-call/responsible owner for each
alert category above, and what response SLA applies. This runbook
cannot responsibly invent an escalation path without that input.

## 7. Environment, domain, TLS

- **`APP_ENV=production`, `APP_DEBUG=false`** — non-negotiable
  (CLAUDE.md). `.env.example`'s own comment already states this; verify
  the actual production `.env` before first deploy.
- **`SESSION_SECURE_COOKIE=true`** once served over HTTPS (Phase 11
  added this placeholder to `.env.example`) — cookies must never be
  sent over plain HTTP in production.
- **`TRUSTED_PROXIES`** (Phase 11) — set to the load balancer/reverse
  proxy's IP/CIDR, or `*` only if that proxy is the sole path to the
  app (e.g. a managed edge). Required for correct HTTPS/client-IP
  detection; see `bootstrap/app.php`.
- **HTTPS/TLS**: terminate TLS at the load balancer or web server
  (Let's Encrypt via certbot, or the platform's managed TLS). Force
  HTTP→HTTPS redirect at that layer, not in application code.
- **DNS**: point the production domain's A/AAAA (or CNAME, if behind a
  managed LB) record at the deployment target once it exists — no
  domain has been configured yet; **decision needed from the user**.
- **CORS**: no `config/cors.php` exists, and none is needed — this
  application has no public cross-origin API surface (server-rendered
  Blade/Livewire only; the WhatsApp webhook and Google OAuth callback
  are both server-to-server/redirect flows, not CORS-relevant). If a
  future phase adds a genuine cross-origin API, add `config/cors.php`
  then, scoped to only the origins that need it.
- **Trusted hosts**: not currently configured (`trustHosts()` in
  `bootstrap/app.php`) — recommended to add once the production domain
  is known, to reject requests with a spoofed `Host` header. Left
  undone this phase since the domain isn't decided yet; add as part of
  the actual go-live configuration step.

## 8. Storage, file storage, and log management

- `FILESYSTEM_DISK=local` by default (`.env.example`) — fine for the
  current V1 surface (no user file uploads exist; knowledge documents
  are pasted text/Markdown, stored in the database, not the
  filesystem). If a future phase adds file uploads, revisit this
  before that phase's go-live, not now.
- `storage/logs/` must be writable by the web server/PHP-FPM user and
  have a retention/rotation policy — Laravel's `daily` log driver
  (used by the new `audit` channel, and recommended for `laravel.log`
  too in production instead of `single`) handles rotation via its
  `days` config; ensure disk space is monitored regardless.
- `php artisan storage:link` if any future phase adds public file
  serving — not currently needed.

## 9. Post-deploy verification

1. `/up` returns 200.
2. Log in as a throwaway/test account (never a demo account left in
   production — see `docs/OPERATIONS.md` §3), reach `/dashboard`, log
   out.
3. `php artisan queue:failed` — empty.
4. `php artisan migrate:status` — all `Ran`.
5. Trigger one real, low-stakes workflow path manually if practical
   (or wait for the next scheduled run) and confirm a
   `WorkflowExecution` row appears.
6. Confirm `storage/logs/audit.log` is being written to (make one
   test role change, confirm the entry appears, matching
   `AuditLoggingTest`'s assertions).

## 10. Explicit approvals still required before go-live

Per Phase 11's instruction, none of the following will be executed
without the user's separate, explicit sign-off:

- Choosing and provisioning the actual hosting platform.
- Setting the production domain and DNS.
- Creating/configuring the production Supabase project (or confirming
  the existing one is to be used for production, not just
  development).
- Running the first production `migrate --force`.
- Enabling the scheduler/queue workers on a live server.
- Connecting real Gmail/WhatsApp/Gemini (or Anthropic) production credentials.
