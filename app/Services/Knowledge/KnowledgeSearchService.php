<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeSearchStatus;
use App\Enums\KnowledgeStatus;
use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Models\KnowledgeChunk;
use App\Models\User;
use App\Support\Knowledge\KnowledgeSearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 10 STEP 20-23/29: authorization-filtered, full-text search over
 * ACTIVE knowledge chunks. This is the retrieval mechanism itself, and
 * it is the one place STEP 20's rule matters most: "never treat the
 * search index as an authorization boundary." Authorization
 * (scopeToAuthorizedDocuments) is applied to the *documents* a chunk
 * may belong to BEFORE the text-match/ranking runs — never as a filter
 * over already-ranked results, and never by trusting anything the
 * caller passes beyond the actor and the caller's own allowed types
 * (search_knowledge, per-agent — see AppServiceProvider).
 *
 * Retrieval mechanism: Postgres native full-text search
 * (`tsvector`/`plainto_tsquery`/`ts_rank`) via the generated
 * `search_vector` column added in add_knowledge_rls_and_constraints —
 * this is CLAUDE.md's approved substitute for embeddings/pgvector/RAG
 * in this phase (see docs/KNOWLEDGE.md). Under SQLite (the automated
 * test suite's driver, which has no tsvector type) this falls back to a
 * plain substring match — clearly weaker, but sufficient to exercise
 * every authorization/status-classification code path without a live
 * Postgres instance.
 */
class KnowledgeSearchService
{
    /**
     * @param  list<KnowledgeType>  $allowedTypes  The calling agent's own permitted knowledge types — never widened by caller input.
     */
    public function search(User $actor, string $query, array $allowedTypes, int $limit = 5): KnowledgeSearchResult
    {
        $query = trim($query);

        if ($query === '' || $allowedTypes === []) {
            return KnowledgeSearchResult::notFound();
        }

        $typeValues = array_map(fn (KnowledgeType $type) => $type->value, $allowedTypes);

        $builder = KnowledgeChunk::query()
            ->with('version.document')
            ->whereHas('version', function (Builder $versionQuery) use ($typeValues, $actor) {
                $versionQuery->where('status', KnowledgeStatus::Active->value)
                    ->whereHas('document', function (Builder $documentQuery) use ($typeValues, $actor) {
                        $documentQuery->whereIn('type', $typeValues);
                        $this->scopeToAuthorizedDocuments($documentQuery, $actor);
                    });
            });

        if (DB::getDriverName() === 'pgsql') {
            $builder->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$query])
                ->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) desc", [$query]);
        } else {
            // SQLite fallback for the automated test suite only — not a
            // claim of real relevance ranking. `plainto_tsquery` ANDs
            // its terms together, so this mirrors that: every query
            // word (not the literal phrase) must appear somewhere in
            // the chunk's heading or content.
            foreach ($this->queryTerms($query) as $term) {
                $builder->where(function (Builder $termQuery) use ($term) {
                    $termQuery->where('content', 'like', '%'.$term.'%')
                        ->orWhere('heading', 'like', '%'.$term.'%');
                });
            }
            $builder->orderByDesc('id');
        }

        $candidates = $builder->limit(max($limit * 4, 20))->get();

        if ($candidates->isEmpty()) {
            return KnowledgeSearchResult::notFound();
        }

        // One best-ranked chunk per distinct document (STEP 39: surface
        // every distinct source rather than several chunks off the one
        // document that happened to rank highest).
        $byDocument = $candidates
            ->unique(fn (KnowledgeChunk $chunk) => $chunk->version->document->id)
            ->take($limit)
            ->values();

        $status = $byDocument->count() > 1
            ? KnowledgeSearchStatus::Conflicting
            : ($this->hasFullTermCoverage($query, $byDocument->first()) ? KnowledgeSearchStatus::Found : KnowledgeSearchStatus::Partial);

        return new KnowledgeSearchResult(
            $status,
            $byDocument->map(fn (KnowledgeChunk $chunk) => $this->toResultEntry($chunk))->all(),
        );
    }

    /**
     * STEP 20/25: filters to documents this actor is permitted to
     * retrieve. Manager sees everything; anyone else sees
     * organisation-wide documents plus their own team's — never a
     * Manager-only document, and never another team's.
     */
    private function scopeToAuthorizedDocuments(Builder $query, User $actor): Builder
    {
        if ($actor->isManager()) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($actor) {
            $scope->where('visibility', KnowledgeVisibility::Organisation->value)
                ->orWhere(function (Builder $teamScope) use ($actor) {
                    $teamScope->where('visibility', KnowledgeVisibility::Team->value)
                        ->where('team_id', $actor->team_id);
                });
        });
    }

    /**
     * @return array{document_id: int, title: string, type: string, version: int, section: ?string, excerpt: string}
     */
    private function toResultEntry(KnowledgeChunk $chunk): array
    {
        $version = $chunk->version;
        $document = $version->document;

        return [
            'document_id' => $document->id,
            'title' => $document->title,
            'type' => $document->type->value,
            'version' => $version->version_number,
            'section' => $chunk->heading,
            'excerpt' => Str::limit($chunk->content, 400),
        ];
    }

    /**
     * A plain, explainable "did every query word actually appear"
     * check — not a fabricated relevance score. Backs the FOUND/PARTIAL
     * distinction (STEP 39) honestly: PARTIAL means the match was real
     * but only covered some of the query's terms.
     */
    private function hasFullTermCoverage(string $query, KnowledgeChunk $chunk): bool
    {
        $haystack = mb_strtolower(($chunk->heading ?? '').' '.$chunk->content);
        $terms = $this->queryTerms($query);

        if ($terms === []) {
            return true;
        }

        return collect($terms)->every(fn (string $term) => str_contains($haystack, $term));
    }

    /**
     * @return list<string>
     */
    private function queryTerms(string $query): array
    {
        return collect(preg_split('/\s+/', mb_strtolower($query)))->filter()->unique()->values()->all();
    }
}
