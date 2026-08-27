<?php

use App\Enums\KnowledgeVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 STEP 4/8: one logical knowledge document (a policy, SOP,
 * playbook, etc.). This row never holds document content — that lives
 * on knowledge_document_versions, one per revision, so a document can
 * be reissued without destroying its history (STEP 34/35). `type` and
 * `visibility` are validated against App\Enums\KnowledgeType/
 * KnowledgeVisibility in the service layer, and constrained at the
 * database level for Postgres in the next migration.
 *
 * `current_version_id` points at whichever version is presently Active
 * (STEP 9 — "the system must always know which version is current").
 * It cannot reference knowledge_document_versions yet (that table is
 * created after this one) — the actual foreign key is added once both
 * tables exist, in add_knowledge_rls_and_constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('type');
            $table->string('visibility')->default(KnowledgeVisibility::Organisation->value);

            // Only meaningful when visibility = 'team'. Mirrors the
            // MessageTemplate/WhatsAppBusinessNumber shape: null =
            // organisation-wide, a team id = scoped to that team.
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->timestamps();

            $table->index('type');
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
