# Deploying to Hostinger (shared hosting) — `app.mbsoft.online`

The full rationale is in `docs/DEPLOYMENT.md`. This is the short version:
two scripts do the server-side work; you do ~4 clicks in hPanel.

## First deployment

### 1. hPanel (once)
- **Domains → Subdomains** → create `app` (→ `app.mbsoft.online`). Note its
  folder, e.g. `~/domains/mbsoft.online/public_html/app`.
- **Security → SSL** → install SSL for `app.mbsoft.online`, turn on **Force HTTPS**.
- **Advanced → PHP Configuration** (for `app.mbsoft.online`) → **PHP 8.3**;
  enable extensions: `pdo_pgsql pgsql mbstring openssl curl xml dom ctype
  fileinfo bcmath tokenizer intl`; set `memory_limit` ≥ 256M,
  `max_execution_time` ≥ 60.
- **Advanced → SSH Access** → enable; note host / port / user.
  (Requires the Premium or Business plan.)

### 2. SSH — get the code and run the script
```bash
ssh -p <port> <user>@<host>

cd ~/domains/mbsoft.online/public_html/app
git clone https://github.com/Mike-Bon/MBSoft-Sales-Lead-Platform.git .
git checkout v1.0.1                 # the release you are deploying
bash deploy/first-deploy.sh
```
The script installs dependencies, writes `.env` (it will ask for the
Supabase **database password** and, optionally, a **Gemini API key**
and a **Brave Search API key**), generates `APP_KEY`, runs the
migration, caches views/events, sets permissions, and can create the
first Manager account.

The AI assistant defaults to `LLM_PROVIDER=gemini` /
`LLM_MODEL=gemini-3.6-flash`; set `LLM_PROVIDER=anthropic` +
`LLM_MODEL=claude-...` in `.env` to use the Anthropic fallback instead.
Gemini is only the reasoning model — external prospect discovery still
uses Brave (`SEARCH_PROVIDER=brave`, `BRAVE_SEARCH_COUNTRY=PH`).

### 3. hPanel (the script prints these exact lines at the end)
- **Subdomains → app.mbsoft.online → Document Root** → append `/public`
  (e.g. `~/domains/mbsoft.online/public_html/app/public`).
- **Advanced → Cron Jobs** → add the **three** cron lines the script
  printed (scheduler; the normal 1-minute queue worker; and — V2.0.3 —
  a dedicated Market Intelligence worker, see "Queue" under Notes).

### 4. Check
Open `https://app.mbsoft.online/` → it redirects to `/login` with your
company branding. Log in as the Manager. Set the company name/logo at
**/company**. Run the smoke test in `docs/DEPLOYMENT.md` §9 / the
project handover notes.

## Later releases
```bash
# on your machine: bump code, `npm run build`, commit public/build, tag, push
git tag v1.0.2 && git push origin main --tags

# on the server:
cd ~/domains/mbsoft.online/public_html/app
bash deploy/redeploy.sh v1.0.2
```

## Notes
- **`public/build/` is committed** — Hostinger shared hosting has no Node
  build step. Rebuild locally (`npm run build`) and commit the result
  whenever frontend assets change.
- **`config:cache` and `route:cache` are skipped on purpose** — this app
  reads `env('TRUSTED_PROXIES')` in `bootstrap/app.php`, and the `/`
  route is a closure. `view:cache` + `event:cache` are used.
- **`proc_open()` is disabled on Hostinger shared hosting** (system-wide,
  not user-configurable). The scripts detect this and: install Composer
  deps with `--no-scripts` then run `artisan package:discover` directly;
  and the cron for the daily workflow calls `artisan workflows:run-daily`
  directly instead of `artisan schedule:run` (which needs `proc_open`).
  The application's own runtime code never uses `proc_open`.
- **Queue** — no persistent worker on shared hosting; a 1‑minute cron
  drains the queue each run (≤ 60 s latency). `queue:work` does not need
  `proc_open`. Move to a VPS + Supervisor if volume grows
  (`docs/DEPLOYMENT.md` §4). Two workers, two crons:

  ```
  # normal jobs — short, fast (comms, knowledge, scheduled workflows)
  * * * * *  cd <app> && php artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> storage/logs/worker.log 2>&1

  # V2.0.3: user-initiated Market Intelligence research — long
  # (~150-270s realistically; hard-capped at the 2400s job timeout).
  # Its own connection has retry_after=3000 (> the job timeout), so a
  # second worker started by the next cron minute can never reserve a
  # job that is still running; it just sees the reservation and exits.
  # No --max-time here: --max-time is checked only BETWEEN jobs and
  # would not interrupt a running research job anyway, and we do not
  # want a short cap racing a legitimate long run. --stop-when-empty
  # makes the worker exit as soon as the MI queue is drained.
  * * * * *  cd <app> && php artisan queue:work market-intelligence --stop-when-empty --tries=1 --timeout=2400 --sleep=5 >> storage/logs/mi-worker.log 2>&1
  ```

  `--timeout` needs the `pcntl` extension to actually kill an overrun
  job; if Hostinger lacks it the enforced per-request cURL timeouts
  (Gemini 90 s, Brave 15 s ×2, page-fetch 8 s ×3 hops) still bound the
  total, and `retry_after` + the job's `WithoutOverlapping` +
  `tries=1` + the `handle()` status guard still make a duplicate
  execution impossible.
- **Supabase** — connect via the **Session pooler**
  (`aws-0-<region>.pooler.supabase.com:5432`), not the direct
  `db.<ref>.supabase.co` host (IPv6‑only). The script defaults to the
  pooler.
- **`.env` never leaves the server.** It is `chmod 600` and sits one
  level above the web document root.
