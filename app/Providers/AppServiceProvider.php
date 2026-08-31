<?php

namespace App\Providers;

use App\Contracts\Ai\LlmProvider;
use App\Contracts\Communication\EmailProvider;
use App\Contracts\Communication\WhatsAppProvider;
use App\Contracts\MarketIntelligence\SearchProvider;
use App\Enums\AgentIdentifier;
use App\Enums\KnowledgeType;
use App\Enums\WorkflowType;
use App\Services\Ai\AgentRegistry;
use App\Services\Ai\Prompts\BusinessDevelopmentAgentPrompt;
use App\Services\Ai\Prompts\CommunicationAgentPrompt;
use App\Services\Ai\Prompts\CostToServeAgentPrompt;
use App\Services\Ai\Prompts\MarketIntelligenceAgentPrompt;
use App\Services\Ai\Prompts\PerformanceAgentPrompt;
use App\Services\Ai\Prompts\SalesAgentPrompt;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\AnalyzeAccountTool;
use App\Services\Ai\Tools\CheckProspectDuplicatesTool;
use App\Services\Ai\Tools\CompareAccountPeriodTool;
use App\Services\Ai\Tools\DiscoverProspectsTool;
use App\Services\Ai\Tools\DraftEmailTool;
use App\Services\Ai\Tools\DraftWhatsAppTool;
use App\Services\Ai\Tools\GetCommunicationHistoryTool;
use App\Services\Ai\Tools\GetCustomerEngagementSummaryTool;
use App\Services\Ai\Tools\GetCustomerRevenueSummaryTool;
use App\Services\Ai\Tools\GetFollowupsTool;
use App\Services\Ai\Tools\GetLeadTool;
use App\Services\Ai\Tools\GetMyPerformanceTool;
use App\Services\Ai\Tools\GetOpportunityTool;
use App\Services\Ai\Tools\GetPipelineSummaryTool;
use App\Services\Ai\Tools\GetRevenueConcentrationTool;
use App\Services\Ai\Tools\GetTeamPerformanceTool;
use App\Services\Ai\Tools\IdentifyAtRiskOpportunitiesTool;
use App\Services\Ai\Tools\IdentifyFollowUpGapsTool;
use App\Services\Ai\Tools\IdentifyMissingInformationTool;
use App\Services\Ai\Tools\IdentifyRevenueExceptionsTool;
use App\Services\Ai\Tools\IdentifyStaleLeadsTool;
use App\Services\Ai\Tools\PrioritizeLeadsTool;
use App\Services\Ai\Tools\QualifyProspectsTool;
use App\Services\Ai\Tools\ScoreProspectsTool;
use App\Services\Ai\Tools\SearchKnowledgeTool;
use App\Services\Ai\Tools\SearchLeadsTool;
use App\Services\Ai\Tools\SearchOpportunitiesTool;
use App\Services\Communication\Providers\GmailEmailProvider;
use App\Services\Communication\Providers\WhatsAppCloudApiProvider;
use App\Services\Knowledge\KnowledgeSearchService;
use App\Services\MarketIntelligence\Providers\BraveSearchProvider;
use App\Services\MarketIntelligence\Providers\NullSearchProvider;
use App\Support\Ai\AgentDefinition;
use Illuminate\Http\Client\Factory as HttpFactory;
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

        // V2.1: external web search provider isolation. Only
        // ProspectDiscoveryService depends on this. No key configured
        // -> NullSearchProvider (discovery reports "not configured",
        // never 500s). Tests bind a fake.
        $this->app->bind(SearchProvider::class, function ($app) {
            $config = $app['config']['services.search'];

            return match ($config['provider'] ?? null) {
                'brave' => new BraveSearchProvider(
                    $app->make(HttpFactory::class),
                    (string) ($config['brave']['api_key'] ?? ''),
                    (int) ($config['timeout'] ?? 15),
                    $config['brave']['country'] ?? null,
                ),
                default => new NullSearchProvider,
            };
        });

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
                // Phase 12: a fourth, deliberate, explicitly-scoped
                // agent — Manager/Team-Head only (AssistantController
                // gates selection and routing; every tool below also
                // re-derives its own authorization from the actor,
                // never trusting that gate alone). No workflow — this
                // phase does not add a scheduled Cost-to-Serve review.
                new AgentDefinition(
                    identifier: AgentIdentifier::CostToServe,
                    name: AgentIdentifier::CostToServe->label(),
                    purpose: 'Revenue and sales-engagement analysis for commercial account review.',
                    systemPrompt: CostToServeAgentPrompt::text(),
                    tools: new ToolRegistry([
                        $app->make(GetCustomerRevenueSummaryTool::class),
                        $app->make(GetCustomerEngagementSummaryTool::class),
                        $app->make(GetRevenueConcentrationTool::class),
                        $app->make(CompareAccountPeriodTool::class),
                        $app->make(IdentifyRevenueExceptionsTool::class),
                        new SearchKnowledgeTool($app->make(KnowledgeSearchService::class), [
                            KnowledgeType::Policy,
                        ]),
                    ]),
                    allowedWorkflows: [],
                    maxToolIterations: $maxIterations,
                ),
                // Phase 13: the Business Development tool category on the
                // same single Agent engine — no orchestrator, no swarm,
                // no agent-to-agent calls. Manager + Team Head only
                // (AgentIdentifier::BusinessDevelopment->isAvailableTo();
                // a Team Member's request falls back to Sales). Reuses
                // the existing read + draft-only tools and adds six
                // read-only analytical tools backed by
                // LeadIntelligenceService. No send/write tool of any
                // kind. No workflow. See docs/BUSINESS_DEVELOPMENT.md.
                new AgentDefinition(
                    identifier: AgentIdentifier::BusinessDevelopment,
                    name: AgentIdentifier::BusinessDevelopment->label(),
                    purpose: 'Transparent lead prioritisation, follow-up/stale/at-risk detection, account intelligence, and draft-only outreach for prospecting decisions.',
                    systemPrompt: BusinessDevelopmentAgentPrompt::text(),
                    tools: new ToolRegistry([
                        $app->make(PrioritizeLeadsTool::class),
                        $app->make(IdentifyStaleLeadsTool::class),
                        $app->make(IdentifyFollowUpGapsTool::class),
                        $app->make(IdentifyAtRiskOpportunitiesTool::class),
                        $app->make(AnalyzeAccountTool::class),
                        $app->make(IdentifyMissingInformationTool::class),
                        $app->make(SearchLeadsTool::class),
                        $app->make(GetLeadTool::class),
                        $app->make(SearchOpportunitiesTool::class),
                        $app->make(GetOpportunityTool::class),
                        $app->make(GetFollowupsTool::class),
                        $app->make(GetPipelineSummaryTool::class),
                        $app->make(GetCommunicationHistoryTool::class),
                        $app->make(GetTeamPerformanceTool::class),
                        $app->make(DraftEmailTool::class),
                        $app->make(DraftWhatsAppTool::class),
                        new SearchKnowledgeTool($app->make(KnowledgeSearchService::class), [
                            KnowledgeType::SalesPlaybook,
                            KnowledgeType::ProductGuide,
                            KnowledgeType::Sop,
                        ]),
                    ]),
                    allowedWorkflows: [],
                    maxToolIterations: $maxIterations,
                ),
                // V2.1–V2.4: the Market Intelligence agent — external
                // prospect discovery, evidence-based qualification,
                // transparent prioritisation scoring, and (V2.4) a
                // single NARROW read-only CRM duplicate check. Same
                // single Agent engine; its entire ToolRegistry is
                // discover_prospects + qualify_prospects + score_prospects
                // + check_prospect_duplicates + a scoped search_knowledge.
                //
                // check_prospect_duplicates is the ONLY tool here with
                // any CRM reach: a bounded, ScopesCrmQueries::scopeToUser
                // -scoped SELECT of `organizations` identity columns.
                // Still NO unrestricted CRM search, NO CRM write / lead
                // creation / assignment / status change, NO draft/send
                // tool, NO Cost-to-Serve tool, NO raw-query tool, NO
                // external web research in the matcher. Duplicate
                // classification is computed by ProspectDuplicateMatcher
                // from deterministic signals, never by the model, and it
                // never mutates the V2.3 score. Manager + Team Head only.
                // No workflow. See docs/MARKET_INTELLIGENCE.md.
                new AgentDefinition(
                    identifier: AgentIdentifier::MarketIntelligence,
                    name: AgentIdentifier::MarketIntelligence->label(),
                    purpose: 'External prospect discovery, qualification, prioritisation scoring, and a narrow authorised CRM duplicate check — no CRM writes, no contact.',
                    systemPrompt: MarketIntelligenceAgentPrompt::text(),
                    tools: new ToolRegistry([
                        $app->make(DiscoverProspectsTool::class),
                        $app->make(QualifyProspectsTool::class),
                        $app->make(ScoreProspectsTool::class),
                        $app->make(CheckProspectDuplicatesTool::class),
                        new SearchKnowledgeTool($app->make(KnowledgeSearchService::class), [
                            KnowledgeType::SalesPlaybook,
                            KnowledgeType::ProductGuide,
                        ]),
                    ]),
                    allowedWorkflows: [],
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
