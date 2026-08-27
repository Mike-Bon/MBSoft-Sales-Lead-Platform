<?php

use App\Enums\KnowledgeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 STEP 8/9/34/35: one immutable revision of a knowledge
 * document's actual content. `raw_content` is the source text/Markdown
 * as uploaded — never edited in place; a correction always creates a
 * new version (status=Processing → Active) and archives the one it
 * replaces (KnowledgeDocumentService::createNewVersion). Only a version
 * with status=Active is ever eligible for retrieval
 * (KnowledgeSearchService); Draft/Processing/Failed are invisible to
 * every agent, Archived is kept for audit/history only.
 *
 * `checksum` (sha256 of raw_content) backs STEP 13's duplicate-upload
 * detection — checked in the service layer against existing versions
 * before a new one is created, not as a database uniqueness constraint
 * (a deliberately identical re-upload of previously-archived content is
 * legitimate and must not be blocked by the schema itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_document_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status')->default(KnowledgeStatus::Draft->value);

            $table->text('raw_content');
            $table->string('checksum', 64);

            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('processing_error')->nullable();

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['knowledge_document_id', 'version_number']);
            $table->index('status');
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_versions');
    }
};
