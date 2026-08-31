<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Services\FiscalPerformanceService;
use App\Services\PerformanceAuthorizer;
use App\Support\Ai\ToolDefinition;
use App\Support\FiscalYear;
use App\Support\Performance\FiscalPerformanceSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * Read-only window onto App\Services\FiscalPerformanceService — the
 * OPERATIONAL (workbook) fiscal-year performance, entirely separate from
 * the CRM Closed-Won pipeline that get_my_performance / get_team_performance
 * report. Every number is application-calculated; the model only explains
 * them.
 *
 * Authorization is re-derived from the acting user via PerformanceAuthorizer
 * exactly as TeamPerformanceController / GetTeamPerformanceTool do — a
 * model-supplied team_id / reporting_unit_id outside the user's scope is
 * DENIED (visibly), never silently substituted. A Team Head/Member who
 * omits team_id gets their own team; only a Manager may see the
 * organisation-wide figure.
 */
class GetFiscalPerformanceTool implements AgentTool
{
    private const MAX_UNIT_ROWS = 15;

    public function __construct(
        private readonly FiscalPerformanceService $fiscal,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_fiscal_performance',
            description: 'Retrieve OPERATIONAL fiscal-year performance from the corporate budget workbook data (monthly phased targets vs monthly actuals, in PHP and units). This is NOT CRM pipeline/Closed-Won performance. Returns full-FY target, YTD phased target, YTD actual, both attainment metrics, variance, remaining target and required per-remaining-month figures, a monthly trend, team totals and a reporting-unit (branch) breakdown. Read-only, authorization-enforced, deterministic. The fiscal year runs December–November.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'fiscal_year' => ['type' => 'integer', 'description' => 'e.g. 2026 = the Dec 2025 – Nov 2026 fiscal year. Defaults to the fiscal year containing today.'],
                    'as_of' => ['type' => 'string', 'description' => 'YYYY-MM-DD reporting date. YTD figures cover fiscal months up to and including this date\'s month. Defaults to today.'],
                    'team_id' => ['type' => 'integer', 'description' => 'Limit to one team. Defaults to the authenticated user\'s own team for a Team Head/Member; a Manager omitting this gets the organisation-wide figure.'],
                    'reporting_unit_id' => ['type' => 'integer', 'description' => 'Limit to one branch / reporting unit (must belong to a team the user may view).'],
                ],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $asOf = isset($arguments['as_of']) ? Carbon::parse($arguments['as_of']) : Carbon::now();
        $fiscalYear = isset($arguments['fiscal_year'])
            ? (int) $arguments['fiscal_year']
            : FiscalYear::containing($asOf)->year;

        if (isset($arguments['reporting_unit_id'])) {
            $unit = ReportingUnit::with('team')->find($arguments['reporting_unit_id']);
            if ($unit === null) {
                throw new AuthorizationException('That reporting unit does not exist.');
            }
            $this->authorizer->authorizeTeam($actor, $unit->team);

            return $this->shape($this->fiscal->forReportingUnit($unit, $fiscalYear, $asOf));
        }

        $teamId = $arguments['team_id'] ?? ($actor->isManager() ? null : $actor->team_id);

        if ($teamId !== null) {
            $team = Team::find($teamId);
            if ($team === null) {
                throw new AuthorizationException('That team does not exist.');
            }
            $this->authorizer->authorizeTeam($actor, $team);

            return $this->shape($this->fiscal->forTeam($team, $fiscalYear, $asOf));
        }

        // Organisation-wide — Manager only.
        $this->authorizer->authorizeOrganisation($actor);

        return $this->shape($this->fiscal->forOrganisation($fiscalYear, $asOf));
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(FiscalPerformanceSnapshot $s): array
    {
        $out = $s->toArray();

        // Keep the tool result bounded: the branch breakdown is
        // truncated worst-first, and the prior-year block keeps only its
        // headline figures (no nested trend/breakdown).
        if (count($out['reporting_unit_breakdown']) > self::MAX_UNIT_ROWS) {
            $out['reporting_unit_breakdown_total'] = count($out['reporting_unit_breakdown']);
            $out['reporting_unit_breakdown'] = array_slice($out['reporting_unit_breakdown'], 0, self::MAX_UNIT_ROWS);
            $out['reporting_unit_breakdown_note'] = 'Showing the '.self::MAX_UNIT_ROWS.' units with the largest YTD revenue gap.';
        }

        if ($out['prior_year'] !== null) {
            $out['prior_year'] = collect($out['prior_year'])
                ->except(['monthly_trend', 'team_totals', 'reporting_unit_breakdown', 'prior_year'])
                ->all();
        }

        $out['note'] = 'Operational performance from the corporate budget workbook — NOT CRM pipeline. "ytd_target_attainment_pct" compares YTD actual against the phased target through the reporting month; "fy_attainment_to_date_pct" compares YTD actual against the full-year target.';

        return $out;
    }
}
