<?php

namespace App\Http\Controllers\Ai;

use App\Enums\AgentIdentifier;
use App\Enums\ProspectResearchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SendAssistantMessageRequest;
use App\Jobs\MarketIntelligence\ProspectResearchJob;
use App\Models\ProspectResearchRun;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\AssistantService;
use App\Services\Ai\ManagementReviewOrchestrator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * STEP 32/41/45: the same plain Blade/session-form assistant interface
 * from Phase 7, now fronting the specialized agents. STEP 17: a user may
 * pick an agent explicitly (the `agent` field); when they don't, STEP
 * 16/18 apply — AgentRouter picks one deterministically, and routing
 * never grants any authorization the picked agent's own tools wouldn't
 * already enforce. A genuinely cross-domain request runs the STEP 20/37
 * Management Review sequence instead of a single agent.
 *
 * V2.0.3: Market Intelligence is the one agent whose execution (Gemini +
 * Brave + public-page fetches, ~150-270s) cannot fit in Hostinger's HTTP
 * window. For that agent ONLY, sendMessage() dispatches ProspectResearchJob
 * and returns immediately; the browser polls research.status and show()
 * settles the finished result back into the session conversation. Every
 * other agent path is byte-for-byte unchanged and still synchronous.
 *
 * Conversation display state lives in the session exactly as in Phase
 * 7; the durable audit trail is AgentInteraction, written by
 * AssistantService regardless of what happens to the session.
 *
 * STEP 37: every action requires authentication (route middleware) and
 * is rate-limited (route throttle) — see routes/ai.php.
 */
class AssistantController extends Controller
{
    private const SESSION_KEY = 'assistant.conversation';

    private const DRAFT_SESSION_KEY = 'assistant.pending_draft';

    private const CONVERSATION_KEY_SESSION = 'assistant.conversation_key';

    public function __construct(
        private readonly AssistantService $assistant,
        private readonly AgentRouter $router,
        private readonly ManagementReviewOrchestrator $managementReview,
    ) {}

    public function show(Request $request): View
    {
        $conversation = $this->settlePendingResearch($request, $request->session()->get(self::SESSION_KEY, []));

        return view('assistant.show', [
            'conversation' => $conversation,
            'draft' => $request->session()->get(self::DRAFT_SESSION_KEY),
            'submissionId' => (string) Str::uuid(),
            // Non-terminal MI runs still shown in this conversation — the
            // view polls these and reloads once they finish.
            'pendingResearchRunIds' => collect($conversation)
                ->filter(fn ($turn) => isset($turn['research_run_id'])
                    && in_array($turn['status'] ?? null, ['queued', 'running'], true))
                ->pluck('research_run_id')
                ->values()
                ->all(),
            // Phase 12: never offer an agent in the dropdown the actor
            // isn't eligible for — server-side validation re-checks this.
            'agents' => array_values(array_filter(AgentIdentifier::cases(), fn (AgentIdentifier $agent) => $agent->isAvailableTo($request->user()))),
        ]);
    }

    public function sendMessage(SendAssistantMessageRequest $request): RedirectResponse
    {
        $message = $request->validated('message');
        $explicitAgent = $request->validated('agent') !== null ? AgentIdentifier::from($request->validated('agent')) : null;
        $conversation = $request->session()->get(self::SESSION_KEY, []);

        $conversation[] = ['role' => 'user', 'content' => $message];

        // STEP 20/37: cross-domain orchestration is only ever considered
        // in auto-routing mode — an explicit agent choice is always
        // respected literally (STEP 17/18).
        if ($explicitAgent === null && $this->router->isManagementReviewRequest($message)) {
            $result = $this->managementReview->run($request->user(), $message);

            $conversation[] = [
                'role' => 'assistant',
                'agent' => null,
                'agent_label' => 'Management Review (Performance + Sales)',
                'content' => $result->summaryText(),
                'tools_used' => array_map(fn (array $call) => $call['name'], $result->toolsUsed()),
                'status' => ($result->performanceAvailable() || $result->salesAvailable()) ? 'completed' : 'failed',
            ];

            $request->session()->put(self::SESSION_KEY, $conversation);
            $request->session()->put(self::DRAFT_SESSION_KEY, null);

            return redirect()->route('assistant.show');
        }

        $agentId = $explicitAgent ?? $this->router->route($message);

        // STEP 18 "routing is not security": eligibility is applied here,
        // once, as a deliberate fallback rather than handing an
        // ineligible actor's auto-routed request to an agent they can't
        // use — never a 403 for a message that merely mentioned a topic.
        if (! $agentId->isAvailableTo($request->user())) {
            $agentId = AgentIdentifier::Sales;
        }

        // ── V2.0.3: Market Intelligence runs asynchronously ──────────
        // This is the ONLY branch that does not call
        // AssistantService::respond() in the web request. No Gemini
        // call, no Brave search, no page fetch happens here.
        if ($agentId === AgentIdentifier::MarketIntelligence) {
            return $this->dispatchResearch($request, $conversation, $message);
        }

        $response = $this->assistant->respond($agentId, $request->user(), $message, $this->historyFor($conversation, $agentId));

        $conversation[] = [
            'role' => 'assistant',
            'agent' => $agentId->value,
            'agent_label' => $agentId->label(),
            'content' => $response->text,
            'tools_used' => array_map(fn (array $call) => $call['name'], $response->toolsUsed),
            'status' => $response->status->value,
        ];

        $request->session()->put(self::SESSION_KEY, $conversation);

        // A new message supersedes any previously pending draft — never
        // let an old draft linger and be actioned against a different,
        // later conversation turn (STEP 19: confirmation must be tied to
        // a specific pending action).
        $request->session()->put(self::DRAFT_SESSION_KEY, $response->draft);

        return redirect()->route('assistant.show');
    }

    public function newConversation(Request $request): RedirectResponse
    {
        $request->session()->forget([self::SESSION_KEY, self::DRAFT_SESSION_KEY, self::CONVERSATION_KEY_SESSION]);

        return redirect()->route('assistant.show');
    }

    public function dismissDraft(Request $request): RedirectResponse
    {
        $request->session()->forget(self::DRAFT_SESSION_KEY);

        return redirect()->route('assistant.show');
    }

    /**
     * V2.0.3: the browser's poll target while a research run is queued /
     * running. Owner-only. Returns the minimum the poller needs — never
     * the result body (that is rendered server-side on the reload the
     * poller triggers once `done` is true).
     */
    public function researchStatus(ProspectResearchRun $researchRun): JsonResponse
    {
        $this->authorize('view', $researchRun);

        return response()->json([
            'status' => $researchRun->status->value,
            'done' => $researchRun->isTerminal(),
        ]);
    }

    /**
     * Idempotently create (or find) the ProspectResearchRun for this
     * submitted turn, dispatch the job only when the row is newly
     * created, and add a single pending turn to the conversation.
     *
     * @param  array<int, array<string, mixed>>  $conversation
     */
    private function dispatchResearch(SendAssistantMessageRequest $request, array $conversation, string $message): RedirectResponse
    {
        $user = $request->user();

        $conversationKey = $request->session()->get(self::CONVERSATION_KEY_SESSION);
        if ($conversationKey === null) {
            $conversationKey = (string) Str::uuid();
            $request->session()->put(self::CONVERSATION_KEY_SESSION, $conversationKey);
        }

        // The idempotency key is sha256(user_id | submission_id). The
        // submission_id is a UUID minted into a hidden field each time
        // the assistant page renders, so a browser re-POST (refresh /
        // back / double-click) resends the identical token and lands on
        // the same run — no second job, no second Brave/Gemini spend.
        // A fresh page render mints a new token, so the user CAN
        // deliberately ask the same question again as a new turn.
        // user_id in the key makes any cross-user collision impossible.
        $token = $request->validated('submission_id') ?: (string) Str::uuid();
        $idempotencyKey = hash('sha256', $user->id.'|'.$token);

        $run = ProspectResearchRun::createOrFirst(
            ['idempotency_key' => $idempotencyKey],
            [
                'user_id' => $user->id,
                'conversation_key' => $conversationKey,
                'message' => $message,
                'status' => ProspectResearchStatus::Queued,
            ],
        );

        if ($run->wasRecentlyCreated) {
            ProspectResearchJob::dispatch($run->id);
        }

        // Add the pending turn once — a duplicate POST for the same run
        // must not stack a second turn.
        $alreadyShown = collect($conversation)->contains(fn ($turn) => ($turn['research_run_id'] ?? null) === $run->id);

        if (! $alreadyShown) {
            $conversation[] = [
                'role' => 'assistant',
                'agent' => AgentIdentifier::MarketIntelligence->value,
                'agent_label' => AgentIdentifier::MarketIntelligence->label(),
                'content' => null,
                'tools_used' => $run->tools_used ?? [],
                'status' => $run->status->value,
                'research_run_id' => $run->id,
            ];
        }

        $request->session()->put(self::SESSION_KEY, $conversation);
        $request->session()->put(self::DRAFT_SESSION_KEY, null);

        return redirect()->route('assistant.show');
    }

    /**
     * Reconcile any pending research turn in this conversation with its
     * ProspectResearchRun row: promote `queued` -> `running`, and settle
     * a finished run's result (or safe failure) into the turn. Persists
     * the conversation back to the session only when something changed.
     *
     * @param  array<int, array<string, mixed>>  $conversation
     * @return array<int, array<string, mixed>>
     */
    private function settlePendingResearch(Request $request, array $conversation): array
    {
        $changed = false;

        foreach ($conversation as $i => $turn) {
            if (! isset($turn['research_run_id'])) {
                continue;
            }

            // Already settled — a terminal status with its content
            // filled in. (A terminal status with null content can happen
            // when an old submission_id is re-POSTed into a fresh
            // conversation; fall through and fill it.)
            if (in_array($turn['status'] ?? null, ['completed', 'failed'], true) && ($turn['content'] ?? null) !== null) {
                continue;
            }

            $run = ProspectResearchRun::find($turn['research_run_id']);

            if ($run === null || $run->user_id !== $request->user()?->id) {
                $conversation[$i]['status'] = 'failed';
                $conversation[$i]['content'] = 'Market Intelligence research could not be completed.';
                $changed = true;

                continue;
            }

            if ($run->status === ProspectResearchStatus::Completed) {
                $conversation[$i]['status'] = 'completed';
                $conversation[$i]['content'] = $run->result ?? '(no response)';
                $conversation[$i]['tools_used'] = $run->tools_used ?? [];
                $changed = true;
            } elseif ($run->status === ProspectResearchStatus::Failed) {
                $conversation[$i]['status'] = 'failed';
                $conversation[$i]['content'] = $run->error_summary ?: 'Market Intelligence research could not be completed. Please try again.';
                $changed = true;
            } elseif ($run->status === ProspectResearchStatus::Running && ($turn['status'] ?? null) !== 'running') {
                $conversation[$i]['status'] = 'running';
                $changed = true;
            }
        }

        if ($changed) {
            $request->session()->put(self::SESSION_KEY, $conversation);
        }

        return $conversation;
    }

    /**
     * STEP 30/31 context isolation: only turns this exact agent itself
     * previously answered are replayed as its history — a turn another
     * agent (or the Management Review orchestrator, or an async Market
     * Intelligence run) answered is never fed into a different agent's
     * context.
     *
     * @param  array<int, array<string, mixed>>  $conversation
     * @return array<int, array<string, mixed>>
     */
    private function historyFor(array $conversation, AgentIdentifier $agentId): array
    {
        $turns = (int) config('services.ai.history_turns', 6);
        $filtered = [];
        $pendingUser = null;

        foreach ($conversation as $turn) {
            if ($turn['role'] === 'user') {
                $pendingUser = $turn;

                continue;
            }

            if (($turn['agent'] ?? null) === $agentId->value && $pendingUser !== null) {
                $filtered[] = ['role' => 'user', 'content' => (string) $pendingUser['content']];
                $filtered[] = ['role' => 'assistant', 'content' => (string) $turn['content']];
            }

            $pendingUser = null;
        }

        return array_slice($filtered, -$turns * 2);
    }
}
