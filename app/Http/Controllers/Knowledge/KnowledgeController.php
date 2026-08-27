<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\StoreKnowledgeDocumentRequest;
use App\Http\Requests\Knowledge\StoreKnowledgeVersionRequest;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Models\Team;
use App\Services\Knowledge\KnowledgeDocumentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 10 STEP 31: knowledge administration. Uploading/versioning
 * content only ever leaves it queued for asynchronous processing
 * (KnowledgeDocumentService dispatches ProcessKnowledgeDocumentVersionJob)
 * — this controller never chunks/indexes anything itself, and a
 * document is never visible to search_knowledge until that job marks
 * its version Active.
 */
class KnowledgeController extends Controller
{
    public function __construct(private readonly KnowledgeDocumentService $documents) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', KnowledgeDocument::class);

        $documents = KnowledgeDocument::query()
            ->with(['createdBy', 'team', 'currentVersion'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (KnowledgeDocument $document) => $request->user()->can('view', $document))
            ->values();

        return view('knowledge.index', ['documents' => $documents]);
    }

    public function create(): View
    {
        $this->authorize('create', KnowledgeDocument::class);

        return view('knowledge.create', ['teams' => Team::orderBy('name')->get()]);
    }

    public function store(StoreKnowledgeDocumentRequest $request): RedirectResponse
    {
        $document = $this->documents->createDocument($request->user(), $request->validated());

        return redirect()->route('knowledge.show', $document)->with('status', 'Document submitted for processing.');
    }

    public function show(KnowledgeDocument $knowledgeDocument): View
    {
        $this->authorize('view', $knowledgeDocument);

        $knowledgeDocument->load(['versions.uploadedBy', 'currentVersion']);

        return view('knowledge.show', ['document' => $knowledgeDocument]);
    }

    public function storeVersion(StoreKnowledgeVersionRequest $request, KnowledgeDocument $knowledgeDocument): RedirectResponse
    {
        $this->documents->createNewVersion($request->user(), $knowledgeDocument, $request->validated());

        return redirect()->route('knowledge.show', $knowledgeDocument)->with('status', 'New version submitted for processing.');
    }

    public function archiveVersion(Request $request, KnowledgeDocument $knowledgeDocument, KnowledgeDocumentVersion $knowledgeDocumentVersion): RedirectResponse
    {
        $this->authorize('update', $knowledgeDocument);

        abort_unless($knowledgeDocumentVersion->knowledge_document_id === $knowledgeDocument->id, 404);

        $this->documents->archiveVersion($knowledgeDocumentVersion);

        return redirect()->route('knowledge.show', $knowledgeDocument)->with('status', 'Version archived.');
    }

    public function destroy(KnowledgeDocument $knowledgeDocument): RedirectResponse
    {
        $this->authorize('delete', $knowledgeDocument);

        $this->documents->delete($knowledgeDocument);

        return redirect()->route('knowledge.index')->with('status', 'Document deleted.');
    }
}
