<?php

use App\Enums\ProspectProposalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5: a prospect → CRM lead proposal awaiting a human's explicit
 * confirmation. It is NOT a Lead and NOT an Organization — it is the
 * structural backbone of "the AI can prepare, only a human can confirm"
 * (spec §2/§17). Same shape/role as `workflow_approvals` (Phase 8): a
 * proposal that persists until a human reviews it, with a one-way status
 * lifecycle and a decided_by/decided_at trail.
 *
 * The `fingerprint` binds a confirmation to the exact proposal content
 * the human reviewed (spec §17); any material change bumps it and the
 * old confirmation stops working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_lead_proposals', function (Blueprint $table) {
            $table->id();

            // Whose review queue this belongs to — the actor who ran the
            // Market Intelligence research. Confirmation must come from
            // this same user.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('status')->default(ProspectProposalStatus::Pending->value);
            $table->string('eligibility');           // App\Enums\ProspectLeadEligibility
            $table->string('policy_version');
            $table->string('fingerprint', 64);       // sha256 hex of the canonical reviewed content

            // Prospect identity (informational + used for the TOCTOU re-check).
            $table->string('business_name');
            $table->string('website')->nullable();
            $table->string('domain')->nullable();

            // The full V2.1–V2.4 intelligence snapshot (score, priority,
            // qualification outcome, duplicate-check result, missing info,
            // a bounded source list). Read-only context for the reviewer;
            // never a Lead field.
            $table->json('prospect_snapshot');

            // The CRM fields that WOULD be created — source-derived, and
            // overwritable by the human at confirmation time through the
            // validated confirm request.
            $table->json('proposed_organization');
            $table->json('proposed_lead');

            // V2.4 result the eligibility was derived from.
            $table->string('duplicate_check_status');     // ok | unavailable | skipped
            $table->string('duplicate_status')->nullable();
            $table->boolean('duplicate_ack_required')->default(false);

            // Set only once the human confirms and the write succeeds.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_lead_proposals');
    }
};
