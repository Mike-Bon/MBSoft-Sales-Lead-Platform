<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a sent Communication back to the WorkflowApproval that
 * authorized it (STEP 42: "Communication sent" must be auditable back
 * to its approval) — null for every communication sent through the
 * ordinary Phase 6 composer or the Phase 7 assistant's draft handoff,
 * both of which never reference an approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->foreignId('workflow_approval_id')->nullable()->after('opportunity_id')->constrained('workflow_approvals')->nullOnDelete();
            $table->index('workflow_approval_id');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_approval_id');
        });
    }
};
