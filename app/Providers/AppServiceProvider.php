<?php

namespace App\Providers;

use App\Contracts\Ai\LlmProvider;
use App\Contracts\Communication\EmailProvider;
use App\Contracts\Communication\WhatsAppProvider;
use App\Services\Ai\Agent;
use App\Services\Ai\CrmAssistantPrompt;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\DraftEmailTool;
use App\Services\Ai\Tools\DraftWhatsAppTool;
use App\Services\Ai\Tools\GetCommunicationHistoryTool;
use App\Services\Ai\Tools\GetContactTool;
use App\Services\Ai\Tools\GetFollowupsTool;
use App\Services\Ai\Tools\GetLeadTool;
use App\Services\Ai\Tools\GetMyPerformanceTool;
use App\Services\Ai\Tools\GetOpportunityTool;
use App\Services\Ai\Tools\GetPipelineSummaryTool;
use App\Services\Ai\Tools\GetTeamPerformanceTool;
use App\Services\Ai\Tools\SearchContactsTool;
use App\Services\Ai\Tools\SearchLeadsTool;
use App\Services\Ai\Tools\SearchOpportunitiesTool;
use App\Services\Communication\Providers\GmailEmailProvider;
use App\Services\Communication\Providers\WhatsAppCloudApiProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // STEP 2 provider isolation: CommunicationService/
        // SendCommunicationJob depend only on these interfaces. Tests
        // swap in fakes by rebinding these, never by touching that code.
        $this->app->bind(EmailProvider::class, GmailEmailProvider::class);
        $this->app->bind(WhatsAppProvider::class, WhatsAppCloudApiProvider::class);

        // Phase 7 STEP 4: provider isolation for the AI layer, mirroring
        // the pattern above exactly. Tests rebind LlmProvider to a fake,
        // never touching Agent/AssistantService/any tool.
        $this->app->bind(LlmProvider::class, AnthropicProvider::class);

        // The one Phase 7 agent (STEP 6): a single configured Agent
        // instance — this specific system prompt plus this specific
        // tool list. A future second agent would be a second such
        // binding elsewhere, reusing this exact Agent engine class with
        // a different prompt/ToolRegistry — no orchestrator, no
        // registry-of-agents, no agent-to-agent delegation.
        $this->app->singleton(Agent::class, function ($app) {
            return new Agent(
                $app->make(LlmProvider::class),
                new ToolRegistry([
                    $app->make(SearchLeadsTool::class),
                    $app->make(GetLeadTool::class),
                    $app->make(SearchContactsTool::class),
                    $app->make(GetContactTool::class),
                    $app->make(SearchOpportunitiesTool::class),
                    $app->make(GetOpportunityTool::class),
                    $app->make(GetMyPerformanceTool::class),
                    $app->make(GetTeamPerformanceTool::class),
                    $app->make(GetPipelineSummaryTool::class),
                    $app->make(GetFollowupsTool::class),
                    $app->make(GetCommunicationHistoryTool::class),
                    $app->make(DraftEmailTool::class),
                    $app->make(DraftWhatsAppTool::class),
                ]),
                CrmAssistantPrompt::text(),
                (int) config('services.ai.max_tool_iterations', 6),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
