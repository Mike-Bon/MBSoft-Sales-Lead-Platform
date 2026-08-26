<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\PerformanceService;
use App\Support\Ai\ToolDefinition;
use App\Support\PerformanceSnapshot;
use Illuminate\Support\Carbon;

/**
 * STEP 12/13: returns exactly the values PerformanceService already
 * calculated — target/actual/achievement/gap/pipeline/coverage/run
 * rate/required run rate. This tool performs no arithmetic of its own;
 * it is a thin, authorized window onto the one authoritative
 * calculation. Always the requesting user's own data — there is no
 * "whose performance" parameter to misuse, since $actor is always the
 * subject.
 */
class GetMyPerformanceTool implements AgentTool
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_my_performance',
            description: 'Retrieve the authenticated user\'s own individual performance (target, actual, achievement, gap, pipeline, coverage, run rate) for a given period. Read-only. These are authoritative application-calculated facts, never to be recalculated.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the start of the current month.'],
                    'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the end of the current month.'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $start = isset($arguments['period_start']) ? Carbon::parse($arguments['period_start']) : Carbon::now()->startOfMonth();
        $end = isset($arguments['period_end']) ? Carbon::parse($arguments['period_end']) : Carbon::now()->endOfMonth();

        return $this->snapshotToArray($this->performance->forIndividual($actor, $start, $end));
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshotToArray(PerformanceSnapshot $snapshot): array
    {
        return [
            'has_target' => $snapshot->hasTarget,
            'target' => $snapshot->hasTarget ? $snapshot->target : null,
            'currency' => $snapshot->currency,
            'actual' => $snapshot->actual,
            'achievement_percent' => $snapshot->achievementPercent,
            'gap' => $snapshot->hasTarget ? $snapshot->gap : null,
            'remaining_target' => $snapshot->hasTarget ? $snapshot->remainingTarget : null,
            'pipeline' => $snapshot->pipeline,
            'pipeline_coverage' => $snapshot->pipelineCoverage,
            'run_rate' => $snapshot->runRate,
            'required_run_rate' => $snapshot->requiredRunRate,
            'period_start' => $snapshot->periodStart->toDateString(),
            'period_end' => $snapshot->periodEnd->toDateString(),
            'period_state' => $snapshot->periodState->value,
        ];
    }
}
