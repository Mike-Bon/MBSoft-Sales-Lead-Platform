<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11: the standard Laravel database-notification schema (STEP 2 —
 * closing the CLAUDE.md V1 gap: "notifications" was named as a V1 core
 * capability but never built in Phases 1-10). `notifiable` is always a
 * User in this application (the only model using the Notifiable trait);
 * `data` holds only the small, non-sensitive fields each notification
 * class explicitly puts there (see App\Notifications) — never a raw
 * model, never message/communication body content.
 *
 * RLS follows the exact same default-deny pattern as every other table
 * in this codebase (ENABLE + FORCE, no policies — see
 * add_crm_rls_and_constraints for the original precedent): the
 * application's own Postgres role has BYPASSRLS and is the sole actor
 * that ever reads/writes this table; RLS here is a safety net against
 * any other, non-application access path, not the authorization
 * mechanism itself (NotificationController enforces that a user can only
 * ever see their own notifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE notifications FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('notifications');
    }
};
