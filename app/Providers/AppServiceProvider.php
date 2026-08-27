<?php

namespace App\Providers;

use App\Contracts\Ai\LlmProvider;
use App\Contracts\Communication\EmailProvider;
use App\Contracts\Communication\WhatsAppProvider;
use App\Enums\AgentIdentifier;
use App\Enums\KnowledgeType;
use App\Enums\WorkflowType;
use App\Services\Ai\AgentRegistry;
use App\Services\Ai\Prompts\CommunicationAgentPrompt;
use App\Services\Ai\Prompts\PerformanceAgentPrompt;
use App\Services\Ai\Prompts\SalesAgentPrompt;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\DraftEmailTool;
use App\Services\Ai\Tools\DraftWhatsAppTool;
use App\Services\Ai\Tools\GetCommunicationHistoryTool;
use App\Services\Ai\Tools\GetFollowupsTool;
use App\Services\Ai\Tools\GetLeadTool;
use App\Services\Ai\Tools\GetMyPerformanceTool;
use App\Services\Ai\Tools\GetOpportunityTool;
use App\Services\Ai\Tools\GetPipelineSummaryTool;
use App\Services\Ai\Tools\GetTeamPerformanceTool;
use App\Services\Ai\Tools\SearchKnowledgeTool;
use App\Services\Ai\Tools\SearchLeadsTool;
use App\Services\Ai\Tools\SearchOpportunitiesTool;
use App\Services\Communication\Providers\GmailEmailProvider;
use App\Services\Communication\Providers\WhatsAppCloudApiProvider;
use App\Services\Knowledge\KnowledgeSearchService;
use App\Support\Ai\AgentDefinition;
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

        // Phase 7 STEP 4 / Phase 9 STEP 29: provider isolation for the
        // AI layer. Every agent depends only on this interface — a
        // future different provider needs one new class, never a
        // rewrite of Agent, AgentRegistry, or any AgentTool.
        $this->app->bind(LlmProvider::class, AnthropicProvider::class);

        // Phase 9 STEP 6/24: the three approved specialized agents and
        // their explicit tool permission matrix — no agent receives a
        // tool it isn't listed here. App\Services\Ai\Agent (the engine)
        // is unchanged from Phase 7; every agent below is just a
        // differently-configured instance of the same generic engine,
        // constructed on demand by AssistantService from the
        // AgentDefinition the router/user selected.
        $this->app->singleton(AgentRegistry::class, function ($app) {
            $maxIterations = (int) config('services.ai.max_tool_iterations', 6);

            return new AgentRegistry([
                new AgentDefinition(
                    identifier: AgentIdentifier::Sales,
                    name: AgentIdentifier::Sales->label(),
                    purpose: 'Pipeline, lead, and opportunity intelligence.',
                    systemPrompt: SalesAgentPrompt::text(),
                    tools: new ToolRegistry([
                        $app->make(SearchLeadsTool::class),
                        $app->make(GetLeadTool::class),
                        $app->make(SearchOpportunitiesTool::class),
                        $app->make(GetOpportunityTool::class),
                        $app->make(GetFollowupsTool::class),
                        $app->make(GetCommunicationHistoryTool::class),
                        $app->make(GetPipelineSummaryTool::class),
                        new SearchKnowledgeTool($app->make(KnowledgeSearchService::class), [
                            KnowledgeType::SalesPlaybook,
                            KnowledgeType::ProductGuide,
                            KnowledgeType::Sop,
                        ]),
                    ]),
                    allowedWorkflows: [WorkflowType::OpportunityAttentionReview],
                    maxToolIterations: $maxIterations,
                ),
                new AgentDefinition(
                    identifier: AgentIdentifier::Performance,
                    name: AgentIdentifier::Performance->label(),
                    purpose: 'Target/achievement/gap/pipeline-coverage interpretation.',
                    systemPrompt: PerformanceAgentPrompt::text(),
                    tools: new ToolRegistry([
                        $app->make(GetMyPerformanceTool::class),
                        $app->make(GetTeamPerformanceTool::class),
                        $app->make(GetPipelineSummaryTool::class),
                        new SearchKnowledgeTool($app->make(KnowledgeSearchService::class), [
                            KnowledgeType::Policy,
                            KnowledgeType::Training,
                        ]),
                    ]),
                    allowedWorkflows: [WorkflowType::PerformanceExceptionReview],
                    maxToolIterations: $maxIterations,
                ),
                new AgentDefinition(
                    identifier: AgentIdentifier::Communication,
                    name: AgentIdentifier::Communication->label(),
                    purpose: 'Follow-up recommendations and draft-only communication.',
                    systemPrompt: CommunicationAgentPrompt::text(),
                    tools: new ToolRegistry([
                        $app->make(GetFollowupsTool::class),
                        $app->make(GetCommunicationHistoryTool::class),
                        $app->make(GetLeadTool::class),
                        $app->make(GetOpportunityTool::class),
                        $app->make(DraftEmailTool::class),
                        $app->make(DraftWhatsAppTool::class),
                        new SearchKnowledgeTool($app->make(KnowledgeSearchService::class), [
                            KnowledgeType::Faq,
                            KnowledgeType::Reference,
                            KnowledgeType::Sop,
                        ]),
                    ]),
                    allowedWorkflows: [WorkflowType::DailyFollowUpReview],
                    maxToolIterations: $maxIterations,
                ),
            ]);
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
