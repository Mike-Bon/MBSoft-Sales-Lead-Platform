<?php

namespace Tests\Feature\Ai;

use App\Enums\AgentIdentifier;
use App\Services\Ai\AgentRouter;
use Tests\TestCase;

/**
 * STEP 49: pure routing logic — no database, no AI provider. STEP 18's
 * "routing is not security" is proven elsewhere (AssistantControllerTest,
 * ToolsTest, SecurityAndInjectionTest all show a misrouted-on-purpose
 * request still can't leak unauthorized data) — this file only proves
 * routing itself is deterministic and matches the specification's own
 * worked examples.
 */
class AgentRouterTest extends TestCase
{
    public function test_pipeline_question_routes_to_sales(): void
    {
        $this->assertSame(AgentIdentifier::Sales, (new AgentRouter)->route('What is my pipeline?'));
    }

    public function test_which_opportunities_should_i_focus_on_routes_to_sales(): void
    {
        $this->assertSame(AgentIdentifier::Sales, (new AgentRouter)->route('Which opportunities should I focus on today?'));
    }

    public function test_why_is_team_behind_target_routes_to_performance(): void
    {
        $this->assertSame(AgentIdentifier::Performance, (new AgentRouter)->route('Why is Team 4 behind target?'));
    }

    public function test_which_teams_are_behind_pace_routes_to_performance(): void
    {
        $this->assertSame(AgentIdentifier::Performance, (new AgentRouter)->route('Which teams are behind pace this month?'));
    }

    public function test_a_generic_attention_question_with_no_clear_domain_falls_back_to_sales(): void
    {
        // "Which teams need attention?" alone is genuinely ambiguous
        // between Sales and Performance framing (no "target"/
        // "performance"/"pace" wording at all) — the router's honest,
        // deterministic default applies rather than guessing.
        $this->assertSame(AgentIdentifier::Sales, (new AgentRouter)->route('Which teams need attention?'));
    }

    public function test_a_lead_prioritisation_question_routes_to_business_development(): void
    {
        $this->assertSame(AgentIdentifier::BusinessDevelopment, (new AgentRouter)->route('Which leads should I prioritize today?'));
        $this->assertSame(AgentIdentifier::BusinessDevelopment, (new AgentRouter)->route('Which of my leads are going cold?'));
        $this->assertSame(AgentIdentifier::BusinessDevelopment, (new AgentRouter)->route('Which opportunities are at risk?'));
        $this->assertSame(AgentIdentifier::BusinessDevelopment, (new AgentRouter)->route('Prepare a call plan for the Globex account.'));
        $this->assertSame(AgentIdentifier::BusinessDevelopment, (new AgentRouter)->route('What information is missing about this prospect?'));
    }

    public function test_business_development_is_checked_before_communication_but_only_for_analysis_phrasing(): void
    {
        // "which leads need follow-up" is analysis → Business Development.
        $this->assertSame(AgentIdentifier::BusinessDevelopment, (new AgentRouter)->route('Who needs follow-up today?'));
        // "draft a follow-up" is a drafting request → Communication still wins.
        $this->assertSame(AgentIdentifier::Communication, (new AgentRouter)->route('Draft a follow-up about the stalled ABC opportunity.'));
    }

    public function test_draft_a_whatsapp_routes_to_communication(): void
    {
        $this->assertSame(AgentIdentifier::Communication, (new AgentRouter)->route('Draft a WhatsApp to John.'));
    }

    public function test_follow_up_wording_routes_to_communication_even_with_sales_vocabulary(): void
    {
        $this->assertSame(AgentIdentifier::Communication, (new AgentRouter)->route('Draft a follow-up about the stalled ABC opportunity.'));
    }

    public function test_an_ambiguous_request_falls_back_to_sales(): void
    {
        $this->assertSame(AgentIdentifier::Sales, (new AgentRouter)->route('What should I look at today?'));
    }

    public function test_routing_is_case_insensitive(): void
    {
        $this->assertSame(AgentIdentifier::Performance, (new AgentRouter)->route('WHY IS TEAM 4 BEHIND TARGET?'));
    }

    public function test_a_management_review_phrase_is_detected(): void
    {
        $this->assertTrue((new AgentRouter)->isManagementReviewRequest('Give me a management review for my team.'));
        $this->assertTrue((new AgentRouter)->isManagementReviewRequest('I need a sales review and how we are tracking against target.'));
    }

    public function test_a_single_domain_question_is_not_treated_as_a_management_review(): void
    {
        $this->assertFalse((new AgentRouter)->isManagementReviewRequest('What is my pipeline?'));
        $this->assertFalse((new AgentRouter)->isManagementReviewRequest('Why is Team 4 behind target?'));
    }
}
