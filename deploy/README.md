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
Supabase **database password** and, optionally, an Anthropic API key),
generates `APP_KEY`, runs the migration, caches views/events, sets
permissions, and can create the first Manager account.

### 3. hPanel (the script prints these exact lines at the end)
- **Subdomains → app.mbsoft.online → Document Root** → append `/public`
  (e.g. `~/domains/mbsoft.online/public_html/app/public`).
- **Advanced → Cron Jobs** → add the two cron lines the script printed
  (scheduler + queue worker, every minute).

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
- **Queue** — no persistent worker on shared hosting; the 1‑minute cron
  drains the queue each run (≤ 60 s latency). Move to a VPS + Supervisor
  if volume grows (`docs/DEPLOYMENT.md` §4).
- **Supabase** — connect via the **Session pooler**
  (`aws-0-<region>.pooler.supabase.com:5432`), not the direct
  `db.<ref>.supabase.co` host (IPv6‑only). The script defaults to the
  pooler.
- **`.env` never leaves the server.** It is `chmod 600` and sits one
  level above the web document root.
