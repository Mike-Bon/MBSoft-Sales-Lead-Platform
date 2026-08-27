<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the three knowledge tables created just before this
 * migration:
 *
 *  1. The `knowledge_documents.current_version_id` → `knowledge_document_versions`
 *     foreign key (deferred here because the versions table didn't
 *     exist yet when documents was created — STEP 9). This part runs on
 *     every driver, including SQLite, via the schema builder.
 *
 *  2. Postgres-only hardening, following the exact pattern used by
 *     every previous phase's RLS migration: CHECK constraints on the
 *     enum-backed string columns, then default-deny RLS on all three
 *     tables (the authoritative access boundary stays
 *     KnowledgeSearchService/KnowledgeDocumentPolicy — see their own
 *     docblocks — this is defense in depth, not a replacement).
 *
 *  3. The Postgres-native full-text search column and its GIN index —
 *     this phase's approved substitute for embeddings/pgvector
 *     (CLAUDE.md V1 exclusions; see docs/KNOWLEDGE.md). A `tsvector`
 *     generated column can't be expressed through the schema builder,
 *     so it's raw SQL, guarded like every other Postgres-only statement
 *     in this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('knowledge_document_versions')->nullOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE knowledge_documents ADD CONSTRAINT knowledge_documents_type_check CHECK (type IN ('policy','sop','sales_playbook','product_guide','training','faq','reference'))");
        DB::statement("ALTER TABLE knowledge_documents ADD CONSTRAINT knowledge_documents_visibility_check CHECK (visibility IN ('organisation','manager','team'))");
        DB::statement("ALTER TABLE knowledge_document_versions ADD CONSTRAINT knowledge_document_versions_status_check CHECK (status IN ('draft','processing','active','archived','failed'))");

        // STEP 12/29: the retrieval mechanism itself. `to_tsvector`
        // combines heading + content so a heading match (e.g. a section
        // literally titled "Refund Policy") ranks a chunk even when the
        // query terms are sparse in the body text.
        DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(heading, '') || ' ' || content)) STORED");
        DB::statement('CREATE INDEX knowledge_chunks_search_vector_gin ON knowledge_chunks USING GIN (search_vector)');

        foreach (['knowledge_documents', 'knowledge_document_versions', 'knowledge_chunks'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['knowledge_documents', 'knowledge_document_versions', 'knowledge_chunks'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }

            DB::statement('DROP INDEX IF EXISTS knowledge_chunks_search_vector_gin');
            DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS search_vector');
            DB::statement('ALTER TABLE knowledge_document_versions DROP CONSTRAINT IF EXISTS knowledge_document_versions_status_check');
            DB::statement('ALTER TABLE knowledge_documents DROP CONSTRAINT IF EXISTS knowledge_documents_visibility_check');
            DB::statement('ALTER TABLE knowledge_documents DROP CONSTRAINT IF EXISTS knowledge_documents_type_check');
        }

        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
    }
};
