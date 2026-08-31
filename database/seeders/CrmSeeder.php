<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Development/demo CRM data only — clearly fictional company and person
 * names, no real personal data. Depends on OrganisationSeeder having
 * already created the demo Manager/Team Heads/Team Members and teams
 * (Team 01 / Team 02 / Team 03).
 *
 * Deliberately spans both Team 01 and Team 02 with distinct owners, plus
 * one organisation-wide (team-less) record, so cross-team denial and
 * Manager-wide visibility are both exercisable by hand after seeding.
 */
class CrmSeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::where('email', 'manager@example.test')->first();

        if (! $manager) {
            $this->command?->warn('OrganisationSeeder has not run yet — skipping CrmSeeder.');

            return;
        }

        // owner_id/team_id are deliberately excluded from these models'
        // $fillable (see each model) so request input can never set them.
        // A trusted seeder is exactly the kind of internal code that
        // needs to bypass that guard directly, the same way factories do.
        Model::unguarded(fn () => $this->seed($manager));
    }

    private function seed(User $manager): void
    {
        $team1 = Team::where('code', 'T01')->first();
        $team2 = Team::where('code', 'T02')->first();
        $head1 = User::where('email', 'teamhead01@example.test')->first();
        $head2 = User::where('email', 'teamhead02@example.test')->first();
        $member1 = User::where('email', 'alex.team01@example.test')->first();
        $member2 = User::where('email', 'alex.team02@example.test')->first();

        // ── Organizations ───────────────────────────────────────────
        $acme = Organization::firstOrCreate(['name' => 'Acme Logistics'], [
            'industry' => 'Logistics', 'website' => 'acmelogistics.example', 'email' => 'hello@acmelogistics.example',
            'city' => 'Austin', 'country' => 'USA', 'source' => 'Referral',
            'owner_id' => $head1->id, 'team_id' => $team1->id,
        ]);

        $borealis = Organization::firstOrCreate(['name' => 'Borealis Robotics'], [
            'industry' => 'Manufacturing', 'website' => 'borealisrobotics.example', 'email' => 'info@borealisrobotics.example',
            'city' => 'Toronto', 'country' => 'Canada', 'source' => 'Trade Show',
            'owner_id' => $member1->id, 'team_id' => $team1->id,
        ]);

        $cascade = Organization::firstOrCreate(['name' => 'Cascade Retail Group'], [
            'industry' => 'Retail', 'website' => 'cascaderetail.example', 'email' => 'contact@cascaderetail.example',
            'city' => 'Seattle', 'country' => 'USA', 'source' => 'Website',
            'owner_id' => $head2->id, 'team_id' => $team2->id,
        ]);

        $driftwood = Organization::firstOrCreate(['name' => 'Driftwood Health Partners'], [
            'industry' => 'Healthcare', 'website' => 'driftwoodhealth.example', 'email' => 'partners@driftwoodhealth.example',
            'city' => 'Denver', 'country' => 'USA', 'source' => 'Cold Outreach',
            'owner_id' => $member2->id, 'team_id' => $team2->id,
        ]);

        $everline = Organization::firstOrCreate(['name' => 'Everline Financial'], [
            'industry' => 'Finance', 'website' => 'everlinefinancial.example',
            'city' => 'New York', 'country' => 'USA', 'source' => 'Referral',
            'owner_id' => $manager->id, 'team_id' => null, // organisation-wide, Manager-owned
        ]);

        // ── Contacts ─────────────────────────────────────────────────
        $contactAcme = Contact::firstOrCreate(
            ['email' => 'jordan.reyes@acmelogistics.example'],
            ['organization_id' => $acme->id, 'first_name' => 'Jordan', 'last_name' => 'Reyes', 'job_title' => 'VP Operations', 'owner_id' => $head1->id, 'team_id' => $team1->id],
        );
        $contactBorealis = Contact::firstOrCreate(
            ['email' => 'priya.natarajan@borealisrobotics.example'],
            ['organization_id' => $borealis->id, 'first_name' => 'Priya', 'last_name' => 'Natarajan', 'job_title' => 'Procurement Lead', 'owner_id' => $member1->id, 'team_id' => $team1->id],
        );
        $contactCascade = Contact::firstOrCreate(
            ['email' => 'sam.okafor@cascaderetail.example'],
            ['organization_id' => $cascade->id, 'first_name' => 'Sam', 'last_name' => 'Okafor', 'job_title' => 'Director of IT', 'owner_id' => $head2->id, 'team_id' => $team2->id],
        );
        $contactDriftwood = Contact::firstOrCreate(
            ['email' => 'lena.park@driftwoodhealth.example'],
            ['organization_id' => $driftwood->id, 'first_name' => 'Lena', 'last_name' => 'Park', 'job_title' => 'Practice Manager', 'owner_id' => $member2->id, 'team_id' => $team2->id],
        );

        // ── Leads (spanning statuses, priorities, follow-up buckets) ──
        $leadOverdue = Lead::firstOrCreate(
            ['organization_id' => $acme->id, 'contact_id' => $contactAcme->id],
            [
                'owner_id' => $head1->id, 'team_id' => $team1->id, 'source' => 'Referral',
                'status' => LeadStatus::Contacted, 'priority' => LeadPriority::High,
                'estimated_value' => 42000, 'currency' => 'PHP',
                'next_follow_up_at' => now()->subDays(3), 'description' => 'Fleet tracking upgrade for 40 vehicles.',
            ],
        );

        $leadDueToday = Lead::firstOrCreate(
            ['organization_id' => $borealis->id, 'contact_id' => $contactBorealis->id],
            [
                'owner_id' => $member1->id, 'team_id' => $team1->id, 'source' => 'Trade Show',
                'status' => LeadStatus::Qualified, 'priority' => LeadPriority::Medium,
                'estimated_value' => 18500, 'currency' => 'PHP',
                'next_follow_up_at' => now()->setTime(17, 0), 'description' => 'Automation line assessment.',
            ],
        );

        $leadUpcoming = Lead::firstOrCreate(
            ['organization_id' => $cascade->id, 'contact_id' => $contactCascade->id],
            [
                'owner_id' => $head2->id, 'team_id' => $team2->id, 'source' => 'Website',
                'status' => LeadStatus::New, 'priority' => LeadPriority::Medium,
                'estimated_value' => 9500, 'currency' => 'PHP',
                'next_follow_up_at' => now()->addWeek(), 'description' => 'POS system refresh across 12 stores.',
            ],
        );

        $leadNoFollowUp = Lead::firstOrCreate(
            ['organization_id' => $driftwood->id, 'contact_id' => $contactDriftwood->id],
            [
                'owner_id' => $member2->id, 'team_id' => $team2->id, 'source' => 'Cold Outreach',
                'status' => LeadStatus::New, 'priority' => LeadPriority::Low,
                'estimated_value' => 6000, 'currency' => 'PHP',
                'description' => 'Scheduling software inquiry.',
            ],
        );

        Lead::firstOrCreate(
            ['organization_id' => $everline->id],
            [
                'owner_id' => $manager->id, 'team_id' => null, 'source' => 'Referral',
                'status' => LeadStatus::Disqualified, 'priority' => LeadPriority::Low,
                'description' => 'Budget frozen this fiscal year.',
            ],
        );

        // ── Opportunities (different stages, including both closed states) ─
        $oppNegotiation = Opportunity::firstOrCreate(
            ['name' => 'Acme Fleet Tracking Rollout'],
            [
                'organization_id' => $acme->id, 'contact_id' => $contactAcme->id, 'lead_id' => $leadOverdue->id,
                'owner_id' => $head1->id, 'team_id' => $team1->id,
                'stage' => OpportunityStage::Negotiation, 'value' => 42000, 'currency' => 'PHP', 'probability' => 75,
                'expected_close_date' => now()->addDays(10),
            ],
        );

        Opportunity::firstOrCreate(
            ['name' => 'Borealis Automation Assessment'],
            [
                'organization_id' => $borealis->id, 'contact_id' => $contactBorealis->id, 'lead_id' => $leadDueToday->id,
                'owner_id' => $member1->id, 'team_id' => $team1->id,
                'stage' => OpportunityStage::Proposal, 'value' => 18500, 'currency' => 'PHP', 'probability' => 50,
                'expected_close_date' => now()->addDays(21),
            ],
        );

        Opportunity::firstOrCreate(
            ['name' => 'Cascade POS Refresh — Won'],
            [
                'organization_id' => $cascade->id, 'contact_id' => $contactCascade->id,
                'owner_id' => $head2->id, 'team_id' => $team2->id,
                'stage' => OpportunityStage::ClosedWon, 'value' => 31000, 'currency' => 'PHP', 'probability' => 100,
                'expected_close_date' => now()->subDays(5),
                // Set explicitly: this opportunity is created directly
                // into a closed stage (not via OpportunityService, which
                // would set this automatically on a real stage
                // transition) — see docs/PERFORMANCE.md on why closed_at,
                // not expected_close_date, is what actual-sales
                // calculations key off.
                'closed_at' => now()->subDays(5),
            ],
        );

        Opportunity::firstOrCreate(
            ['name' => 'Driftwood Scheduling Pilot — Lost'],
            [
                'organization_id' => $driftwood->id, 'contact_id' => $contactDriftwood->id,
                'owner_id' => $member2->id, 'team_id' => $team2->id,
                'stage' => OpportunityStage::ClosedLost, 'value' => 6000, 'currency' => 'PHP', 'probability' => 0,
                'expected_close_date' => now()->subDays(2),
                'closed_at' => now()->subDays(2),
            ],
        );

        // ── Activities on the timeline-rich lead ────────────────────
        if (Activity::where('lead_id', $leadOverdue->id)->doesntExist()) {
            Activity::create([
                'user_id' => $head1->id, 'team_id' => $team1->id, 'lead_id' => $leadOverdue->id,
                'organization_id' => $acme->id, 'contact_id' => $contactAcme->id,
                'type' => ActivityType::Call, 'subject' => 'Discovery call', 'description' => 'Discussed fleet size and rollout timeline.',
                'occurred_at' => now()->subDays(10),
            ]);
            Activity::create([
                'user_id' => $head1->id, 'team_id' => $team1->id, 'lead_id' => $leadOverdue->id,
                'organization_id' => $acme->id, 'contact_id' => $contactAcme->id,
                'type' => ActivityType::Meeting, 'subject' => 'On-site demo', 'description' => 'Demoed tracking dashboard to ops team.',
                'occurred_at' => now()->subDays(6),
            ]);
            Activity::create([
                'user_id' => $head1->id, 'team_id' => $team1->id, 'lead_id' => $leadOverdue->id,
                'organization_id' => $acme->id, 'contact_id' => $contactAcme->id,
                'type' => ActivityType::Proposal, 'subject' => 'Sent proposal', 'description' => 'Proposal for 40-vehicle rollout sent for review.',
                'occurred_at' => now()->subDays(4),
            ]);
        }

        $this->command?->info('CRM demo data seeded: 5 organizations, 4 contacts, 5 leads, 4 opportunities, 3 activities.');
    }
}
