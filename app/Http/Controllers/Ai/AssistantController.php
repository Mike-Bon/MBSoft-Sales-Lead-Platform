<?php

namespace App\Http\Controllers\Ai;

use App\Enums\AgentIdentifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SendAssistantMessageRequest;
use App\Services\Ai\AgentRouter;
use App\Services\Ai\AssistantService;
use App\Services\Ai\ManagementReviewOrchestrator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * STEP 32/41/45: the same plain Blade/session-form assistant interface
 * from Phase 7, now fronting the three Phase 9 specialized agents.
 * STEP 17: a user may pick an agent explicitly (the `agent` field); when
 * they don't, STEP 16/18 apply — AgentRouter picks one deterministically,
 * and routing never grants any authorization the picked agent's own
 * tools wouldn't already enforce on their own. A genuinely cross-domain
 * request (only ever detected in auto-routing mode, never overriding an
 * explicit choice) runs the STEP 20/37 Management Review sequence
 * instead of a single agent.
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

    public function __construct(
        private readonly AssistantService $assistant,
        private readonly AgentRouter $router,
        private readonly ManagementReviewOrchestrator $managementReview,
    ) {}

    public function show(Request $request): View
    {
        return view('assistant.show', [
            'conversation' => $request->session()->get(self::SESSION_KEY, []),
            'draft' => $request->session()->get(self::DRAFT_SESSION_KEY),
            // Phase 12: never offer an agent in the dropdown the actor
            // isn't eligible for (Cost-to-Serve is Manager/Team-Head
            // only) — server-side validation re-checks this regardless.
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
        // respected literally, never silently upgraded to a multi-agent
        // run (STEP 17/18).
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

        // STEP 18 "routing is not security" holds: AgentRouter itself
        // stays a pure topic classifier with no notion of the actor.
        // Eligibility (Phase 12: Cost-to-Serve is Manager/Team-Head
        // only) is applied here, once, as a deliberate fallback rather
        // than ever handing an ineligible actor's auto-routed request
        // to an agent they can't use — never a 403 for a message that
        // merely happened to mention "cost to serve" in passing.
        if (! $agentId->isAvailableTo($request->user())) {
            $agentId = AgentIdentifier::Sales;
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
        // later conversation turn (STEP 19's "confirmation must be tied
        // to a specific pending action").
        $request->session()->put(self::DRAFT_SESSION_KEY, $response->draft);

        return redirect()->route('assistant.show');
    }

    public function newConversation(Request $request): RedirectResponse
    {
        $request->session()->forget([self::SESSION_KEY, self::DRAFT_SESSION_KEY]);

        return redirect()->route('assistant.show');
    }

    public function dismissDraft(Request $request): RedirectResponse
    {
        $request->session()->forget(self::DRAFT_SESSION_KEY);

        return redirect()->route('assistant.show');
    }

    /**
     * STEP 30/31 context isolation: only turns this exact agent itself
     * previously answered are replayed as its history — a turn another
     * agent (or the Management Review orchestrator) answered is never
     * fed into a different agent's context.
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
