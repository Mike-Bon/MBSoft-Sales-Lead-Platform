<?php

namespace App\Jobs\MarketIntelligence;

use App\Enums\AgentIdentifier;
use App\Enums\AgentInteractionStatus;
use App\Enums\ProspectResearchStatus;
use App\Models\AgentInteraction;
use App\Models\ProspectResearchRun;
use App\Services\Ai\AssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * V2.0.3: runs ONE user-initiated Market Intelligence request outside the
 * HTTP request. It does NOT reimplement any agent logic — it calls the
 * exact same AssistantService::respond() the synchronous assistant path
 * and the Phase 8 workflow jobs use, as the ORIGINAL requesting user, so
 * every CRM/team scope, Cost-to-Serve isolation, rate limit and the
 * human-confirmation boundary are unchanged.
 *
 * Runs on its own `market-intelligence` connection/queue (retry_after
 * far exceeds this job's timeout — config/queue.php), so a second cron
 * worker can never reserve a still-running research job. tries = 1: a
 * failed or timed-out research pipeline is terminal and requires a new,
 * explicit user request — it is NEVER auto-retried (the MI services
 * charge their hourly rate limiter at the start of each call; a retry
 * would double-charge and re-spend Brave/Gemini budget).
 */
class ProspectResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CONNECTION = 'market-intelligence';

    public int $tries = 1;

    /**
     * Headroom over the inspected theoretical worst case (~2040s of
     * cURL-enforced I/O: up to 6 Gemini calls @ 90s + up to 9 Brave
     * searches @ 15s x2 + up to 20 page fetches @ 8s x3 hops). Must stay
     * below the connection retry_after (config/queue.php, 3000s).
     */
    public int $timeout = 2400;

    public function __construct(public readonly int $runId)
    {
        // Dedicated connection + queue (config/queue.php): retry_after
        // there is far larger than $timeout, so a second cron worker can
        // never reserve a research job that is still running.
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::CONNECTION);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Belt-and-braces against a duplicate execution of THIS run (the
        // retry_after > timeout math already prevents it). Different
        // runs are never blocked. dontRelease + tries=1: an unexpected
        // overlap is dropped, not retried — handle()'s status guard
        // makes any second pass a no-op anyway.
        return [
            (new WithoutOverlapping('mi-research:'.$this->runId))
                ->dontRelease()
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(AssistantService $assistant): void
    {
        $run = ProspectResearchRun::find($this->runId);

        // Already picked up (double-reserved), already finished, or the
        // row/user is gone — never run the pipeline twice.
        if ($run === null || $run->status !== ProspectResearchStatus::Queued) {
            return;
        }

        if ($run->user === null) {
            $run->markFailed('Market Intelligence research could not be completed.');

            return;
        }

        $run->markRunning();

        $lastInteractionIdBefore = (int) (AgentInteraction::max('id') ?? 0);

        // AssistantService::respond() never throws — it catches
        // AiProviderException and returns a Failed AgentResponse whose
        // text is already a safe, generic message.
        $response = $assistant->respond(AgentIdentifier::MarketIntelligence, $run->user, $run->message);

        $agentInteractionId = AgentInteraction::query()
            ->where('id', '>', $lastInteractionIdBefore)
            ->where('user_id', $run->user_id)
            ->orderBy('id')
            ->value('id');

        if ($response->status === AgentInteractionStatus::Failed) {
            $run->markFailed($response->text ?: 'Market Intelligence research could not be completed.');

            return;
        }

        $run->markCompleted(
            $response->text ?? '',
            // Tool NAMES only — never the model-supplied arguments.
            array_values(array_unique(array_map(fn (array $call) => $call['name'], $response->toolsUsed))),
            $agentInteractionId,
        );
    }

    public function failed(?Throwable $e): void
    {
        $run = ProspectResearchRun::find($this->runId);

        if ($run !== null && ! $run->isTerminal()) {
            // Generic only — never $e->getMessage()/stack trace/provider detail.
            $run->markFailed('Market Intelligence research could not be completed. Please try again.');
        }
    }
}
