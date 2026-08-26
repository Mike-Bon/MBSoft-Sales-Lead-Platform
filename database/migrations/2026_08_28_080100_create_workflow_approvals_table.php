<?php

use App\Enums\ApprovalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 19/20: a workflow-produced communication draft awaiting human
 * approval. Distinct from a Phase 7 chat draft (which lives only in
 * session state for the duration of a live conversation) because a
 * workflow runs unattended — its output must persist until a human
 * reviews it later, potentially the next time they open the app.
 *
 * Mirrors communications' own CRM-reference shape so the eventual real
 * Communication (created only via CommunicationService, once approved —
 * see communications.workflow_approval_id) is a faithful continuation
 * of what was proposed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_execution_id')->constrained('workflow_executions')->cascadeOnDelete();

            // Whose approval queue this appears in — always the same
            // user the parent execution's scope belongs to.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('channel');
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->text('body');

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->foreignId('whatsapp_number_id')->nullable()->constrained('whatsapp_business_numbers')->nullOnDelete();

            $table->string('status')->default(ApprovalStatus::Pending->value);

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
        Schema::dropIfExists('workflow_approvals');
    }
};
