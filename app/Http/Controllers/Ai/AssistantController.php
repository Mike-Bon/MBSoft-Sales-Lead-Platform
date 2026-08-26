<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SendAssistantMessageRequest;
use App\Services\Ai\AssistantService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * STEP 32: a simple assistant interface reusing this application's own
 * plain Blade/session-form architecture (this app does not build chat
 * UIs as a client-side SPA anywhere else, and Phase 7 doesn't start
 * here) — not a separate application. Conversation display state lives
 * in the session (ephemeral, per-browser-tab UI convenience); the
 * durable audit trail is AgentInteraction, written by AssistantService
 * regardless of what happens to the session.
 *
 * STEP 37: every action requires authentication (route middleware) and
 * is rate-limited (route throttle) — see routes/ai.php.
 */
class AssistantController extends Controller
{
    private const SESSION_KEY = 'assistant.conversation';

    private const DRAFT_SESSION_KEY = 'assistant.pending_draft';

    public function __construct(private readonly AssistantService $assistant) {}

    public function show(Request $request): View
    {
        return view('assistant.show', [
            'conversation' => $request->session()->get(self::SESSION_KEY, []),
            'draft' => $request->session()->get(self::DRAFT_SESSION_KEY),
        ]);
    }

    public function sendMessage(SendAssistantMessageRequest $request): RedirectResponse
    {
        $message = $request->validated('message');
        $conversation = $request->session()->get(self::SESSION_KEY, []);

        $response = $this->assistant->respond($request->user(), $message, $this->historyFor($conversation));

        $conversation[] = ['role' => 'user', 'content' => $message];
        $conversation[] = [
            'role' => 'assistant',
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
     * @param  array<int, array<string, mixed>>  $conversation
     * @return array<int, array<string, mixed>>
     */
    private function historyFor(array $conversation): array
    {
        $turns = (int) config('services.ai.history_turns', 6);

        return collect($conversation)
            ->slice(-$turns * 2)
            ->map(fn (array $turn) => ['role' => $turn['role'], 'content' => (string) $turn['content']])
            ->values()
            ->all();
    }
}
