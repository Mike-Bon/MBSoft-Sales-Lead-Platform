#!/usr/bin/env bash
#
# One-shot first deployment for Hostinger shared hosting.
# Run this once, over SSH, from the repository root after `git clone`.
#
#   cd ~/domains/mbsoft.online/public_html/app
#   git clone https://github.com/Mike-Bon/MBSoft-Sales-Lead-Platform.git .
#   git checkout v1.0.2        # or the tag you are deploying
#   bash deploy/first-deploy.sh
#
# It does: composer install, writes .env (prompts for the DB password),
# generates APP_KEY, runs the database migration, caches views/events,
# fixes permissions, and offers to create the first Manager account.
#
# It does NOT: create the subdomain, set the PHP version, set the document
# root, or add the cron jobs — those are 4 clicks in hPanel and the script
# prints exactly what to enter at the end.
#
# Hostinger shared hosting disables proc_open() system-wide. The script
# detects that and (a) installs with --no-scripts + runs package:discover
# directly, (b) prints a cron that calls the workflow command directly
# instead of `schedule:run` (which needs proc_open).
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"
echo "==> App directory: $APP_DIR"

# ---------------------------------------------------------------------------
# 1. Locate a PHP 8.x CLI binary
# ---------------------------------------------------------------------------
PHP=""
for c in php8.3 php8.2 php /usr/bin/php8.3 /usr/bin/php8.2 /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
    if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' 2>/dev/null; then
        PHP="$c"; break
    fi
done
[ -n "$PHP" ] || { echo "ERROR: no PHP >= 8.2 CLI found. Set PHP 8.3 in hPanel -> PHP Configuration, then re-run."; exit 1; }
echo "==> PHP: $PHP ($($PHP -r 'echo PHP_VERSION;'))"

# proc_open is disabled on Hostinger shared hosting — detect it.
if $PHP -r 'exit(function_exists("proc_open") ? 0 : 1);' 2>/dev/null; then
    HAS_PROC_OPEN=1
    echo "==> proc_open: available"
else
    HAS_PROC_OPEN=0
    echo "==> proc_open: DISABLED (shared hosting) — using --no-scripts + direct package:discover"
fi

# ---------------------------------------------------------------------------
# 2. Locate Composer
# ---------------------------------------------------------------------------
if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
elif [ -f composer.phar ]; then
    COMPOSER="$PHP composer.phar"
else
    echo "==> Composer not found — downloading composer.phar locally..."
    $PHP -r "copy('https://getcomposer.org/installer','composer-setup.php');"
    $PHP composer-setup.php --quiet
    rm -f composer-setup.php
    COMPOSER="$PHP composer.phar"
fi
echo "==> Composer: $COMPOSER"

# ---------------------------------------------------------------------------
# 3. Install PHP dependencies (production)
# ---------------------------------------------------------------------------
echo "==> composer install --no-dev ..."
if [ "$HAS_PROC_OPEN" -eq 1 ]; then
    COMPOSER_MEMORY_LIMIT=-1 $COMPOSER install --no-dev --optimize-autoloader --no-interaction
else
    COMPOSER_MEMORY_LIMIT=-1 $COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-scripts
    echo "==> Running post-install artisan step directly ..."
    $PHP artisan package:discover --ansi
fi

# ---------------------------------------------------------------------------
# 4. .env
# ---------------------------------------------------------------------------
if [ -f .env ]; then
    echo "==> .env already exists — leaving it untouched."
else
    read -r -p "App URL [https://app.mbsoft.online]: " APP_URL_IN
    APP_URL_IN="${APP_URL_IN:-https://app.mbsoft.online}"
    APP_HOST="${APP_URL_IN#https://}"; APP_HOST="${APP_HOST#http://}"; APP_HOST="${APP_HOST%%/*}"

    read -r -p "Supabase pooler host [aws-0-ap-south-1.pooler.supabase.com]: " DB_HOST_IN
    DB_HOST_IN="${DB_HOST_IN:-aws-0-ap-south-1.pooler.supabase.com}"
    read -r -p "Supabase user [postgres.yqhnpxcgthpavysskpai]: " DB_USER_IN
    DB_USER_IN="${DB_USER_IN:-postgres.yqhnpxcgthpavysskpai}"
    read -r -s -p "Supabase database password: " DB_PASS_IN; echo
    [ -n "$DB_PASS_IN" ] || { echo "ERROR: database password is required."; exit 1; }

    read -r -p "Gemini API key (Google AI Studio; optional, Enter to skip — AI stays dormant): " LLM_KEY_IN
    read -r -p "Brave Search API key (optional, Enter to skip — prospect discovery stays off): " BRAVE_KEY_IN

    echo "==> Writing .env ..."
    cat > .env <<ENV
APP_NAME="MBSoft"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${APP_URL_IN}
APP_TIMEZONE=Asia/Manila
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

TRUSTED_PROXIES=*
LOG_AUDIT_RETENTION_DAYS=365
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=30
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=${DB_HOST_IN}
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=${DB_USER_IN}
DB_PASSWORD=${DB_PASS_IN}
DB_SSLMODE=require

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=${APP_HOST}
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@${APP_HOST#app.}"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"

LLM_PROVIDER=gemini
LLM_API_KEY=${LLM_KEY_IN}
LLM_MODEL=gemini-2.5-flash
LLM_MAX_TOKENS=1024
LLM_TIMEOUT_SECONDS=30
AI_MAX_TOOL_ITERATIONS=6
AI_MAX_MESSAGE_LENGTH=2000
AI_HISTORY_TURNS=6

SEARCH_PROVIDER=${BRAVE_KEY_IN:+brave}
SEARCH_HTTP_TIMEOUT=15
BRAVE_SEARCH_API_KEY=${BRAVE_KEY_IN}
BRAVE_SEARCH_COUNTRY=PH

WORKFLOW_DAILY_FOLLOW_UP_REVIEW_ENABLED=true
WORKFLOW_OPPORTUNITY_ATTENTION_REVIEW_ENABLED=true
WORKFLOW_PERFORMANCE_EXCEPTION_REVIEW_ENABLED=true
WORKFLOW_RUN_AT=08:00
WORKFLOW_APPROVAL_TTL_DAYS=3
WORKFLOW_STALLED_OPPORTUNITY_DAYS=14
WORKFLOW_CLOSING_SOON_DAYS=7

BD_STALE_LEAD_DAYS=10
BD_STALLED_OPPORTUNITY_DAYS=21
BD_RECENT_ENGAGEMENT_DAYS=7
BD_MAX_RESULTS_PER_QUERY=25
BD_HIGH_VALUE_THRESHOLD=50000

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_API_TOKEN=
WHATSAPP_APP_SECRET=
WHATSAPP_API_VERSION=v20.0
WHATSAPP_WEBHOOK_VERIFY_TOKEN=
ENV
    chmod 600 .env
fi

# ---------------------------------------------------------------------------
# 5. APP_KEY (only if empty)
# ---------------------------------------------------------------------------
if grep -qE '^APP_KEY=$' .env; then
    echo "==> Generating APP_KEY ..."
    $PHP artisan key:generate --force
else
    echo "==> APP_KEY already set — not regenerating (would break sessions)."
fi

# ---------------------------------------------------------------------------
# 6. Database migration
# ---------------------------------------------------------------------------
echo "==> Checking database connection & pending migrations ..."
$PHP artisan migrate:status
echo
read -r -p "Run 'php artisan migrate --force' now? [y/N]: " RUNMIG
case "$RUNMIG" in
    y|Y|yes|YES)
        $PHP artisan migrate --force
        $PHP artisan migrate:status | tail -5
        ;;
    *)
        echo "==> Skipped. Run '$PHP artisan migrate --force' yourself before going live."
        ;;
esac

# ---------------------------------------------------------------------------
# 7. Caches + permissions
# ---------------------------------------------------------------------------
echo "==> Caching views & events (route:cache / config:cache deliberately skipped) ..."
$PHP artisan view:cache
$PHP artisan event:cache
chmod -R 775 storage bootstrap/cache
echo "==> Permissions set."

# ---------------------------------------------------------------------------
# 8. First Manager account (optional)
# ---------------------------------------------------------------------------
echo
read -r -p "Create the first Manager account now? [y/N]: " MKMGR
case "$MKMGR" in
    y|Y|yes|YES)
        read -r -p "  Manager full name: " MGR_NAME
        read -r -p "  Manager email: " MGR_EMAIL
        read -r -s -p "  Manager password: " MGR_PASS; echo
        MGR_NAME="$MGR_NAME" MGR_EMAIL="$MGR_EMAIL" MGR_PASS="$MGR_PASS" $PHP artisan tinker --no-interaction <<'PHP'
$email = getenv('MGR_EMAIL');
if (App\Models\User::where('email', $email)->exists()) {
    echo "A user with that email already exists — skipped.\n";
} else {
    $u = new App\Models\User;
    $u->name = getenv('MGR_NAME');
    $u->email = $email;
    $u->password = getenv('MGR_PASS');
    $u->role = App\Enums\UserRole::Manager;
    $u->email_verified_at = now();
    $u->save();
    echo "Created Manager #{$u->id} ({$u->email}).\n";
}
PHP
        ;;
esac

# ---------------------------------------------------------------------------
# 9. What you still have to do in hPanel
# ---------------------------------------------------------------------------
if [ "$HAS_PROC_OPEN" -eq 1 ]; then
    SCHED_CRON="* * * * *  cd ${APP_DIR} && ${PHP} artisan schedule:run >> /dev/null 2>&1"
else
    SCHED_CRON="0 8 * * *  cd ${APP_DIR} && ${PHP} artisan workflows:run-daily >> storage/logs/workflows.log 2>&1"
fi

cat <<DONE

============================================================
 Server-side setup complete. Finish these in hPanel:
============================================================

1) Subdomains -> app.mbsoft.online -> Document Root:
      ${APP_DIR}/public

2) Advanced -> Cron Jobs -> add BOTH:

   # daily agentic workflow
   ${SCHED_CRON}

   # queue worker (every minute; drains the queue then exits)
   * * * * *  cd ${APP_DIR} && ${PHP} artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> storage/logs/worker.log 2>&1

3) Security -> SSL -> ensure SSL is active for app.mbsoft.online and
   "Force HTTPS" is on.

Then open https://app.mbsoft.online/  -> should redirect to /login.
============================================================
DONE
