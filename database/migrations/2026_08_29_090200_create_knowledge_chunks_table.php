<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 STEP 12: a section-sized piece of one version's content —
 * split by DocumentChunker (heading-aware, falling back to fixed-size
 * paragraph groups for content with no headings) so retrieval returns a
 * focused excerpt rather than an entire document, and so
 * search_knowledge's context stays bounded (STEP 45 cost control).
 *
 * The actual full-text-search column (`search_vector`, a Postgres
 * generated tsvector, STEP 12/29 — this codebase's substitute for
 * embeddings/pgvector, see docs/KNOWLEDGE.md) is added in the next,
 * Postgres-only migration; it cannot be part of this driver-agnostic
 * `Schema::create` because SQLite (the automated test suite's driver)
 * has no tsvector type. KnowledgeSearchService falls back to a plain
 * `content LIKE` match against this table under SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_document_version_id')->constrained()->cascadeOnDelete();
            $table->string('heading')->nullable();
            $table->unsignedInteger('section_order')->default(0);
            $table->text('content');

            $table->timestamps();

            $table->index('knowledge_document_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
