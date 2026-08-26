<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-only hardening for the four new communication tables,
 * following the exact pattern used by every previous phase's RLS
 * migration (see Phase 2/3/4 for the full write-up on why this is a
 * default-deny safety net, not the authoritative access-control layer —
 * that remains App\Services\Communication\CommunicationAuthorizer and
 * the CRM policies communications are attached to).
 *
 * Also adds the STEP 22 idempotency guarantee at the database level: a
 * partial unique index on communications.provider_message_id ensures
 * the same provider message/event can never be recorded twice, even if
 * a webhook is retried or a queue worker double-processes a job.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE communications ADD CONSTRAINT communications_channel_check CHECK (channel IN ('email', 'whatsapp'))");
        DB::statement("ALTER TABLE communications ADD CONSTRAINT communications_direction_check CHECK (direction IN ('outbound', 'inbound'))");
        DB::statement("ALTER TABLE communications ADD CONSTRAINT communications_status_check CHECK (status IN ('queued', 'sending', 'sent', 'delivered', 'read', 'failed'))");

        DB::statement("ALTER TABLE email_accounts ADD CONSTRAINT email_accounts_status_check CHECK (status IN ('connected', 'disconnected', 'needs_reauth'))");
        DB::statement("ALTER TABLE whatsapp_business_numbers ADD CONSTRAINT whatsapp_numbers_status_check CHECK (status IN ('connected', 'disconnected', 'error'))");
        DB::statement("ALTER TABLE message_templates ADD CONSTRAINT message_templates_channel_check CHECK (channel IN ('email', 'whatsapp'))");
        DB::statement("ALTER TABLE message_templates ADD CONSTRAINT message_templates_status_check CHECK (status IN ('active', 'inactive'))");

        // STEP 22 idempotency: the same provider message/event id can
        // never be stored twice. NULLs (not-yet-sent outbound records)
        // are exempt from uniqueness by Postgres's own semantics, so
        // this only ever constrains rows that actually have one.
        DB::statement('
            CREATE UNIQUE INDEX communications_unique_provider_message_id
            ON communications (provider, provider_message_id)
            WHERE provider_message_id IS NOT NULL
        ');

        foreach (['email_accounts', 'whatsapp_business_numbers', 'message_templates', 'communications'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['email_accounts', 'whatsapp_business_numbers', 'message_templates', 'communications'] as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP INDEX IF EXISTS communications_unique_provider_message_id');
        DB::statement('ALTER TABLE communications DROP CONSTRAINT IF EXISTS communications_channel_check');
        DB::statement('ALTER TABLE communications DROP CONSTRAINT IF EXISTS communications_direction_check');
        DB::statement('ALTER TABLE communications DROP CONSTRAINT IF EXISTS communications_status_check');
        DB::statement('ALTER TABLE email_accounts DROP CONSTRAINT IF EXISTS email_accounts_status_check');
        DB::statement('ALTER TABLE whatsapp_business_numbers DROP CONSTRAINT IF EXISTS whatsapp_numbers_status_check');
        DB::statement('ALTER TABLE message_templates DROP CONSTRAINT IF EXISTS message_templates_channel_check');
        DB::statement('ALTER TABLE message_templates DROP CONSTRAINT IF EXISTS message_templates_status_check');
    }
};
