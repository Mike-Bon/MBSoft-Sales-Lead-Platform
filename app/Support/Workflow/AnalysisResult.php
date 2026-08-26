<?php

namespace App\Support\Workflow;

/**
 * STEP 36/37: the output of one workflow's deterministic Laravel-side
 * analysis, before the AI agent is ever involved. `hasFindings = false`
 * lets WorkflowExecutionService skip calling the agent entirely (cost
 * control — there is nothing to interpret) and record a plain,
 * deterministic "all clear" result instead.
 */
final readonly class AnalysisResult
{
    /**
     * @param  array<string, mixed>  $findings  Structured, JSON-storable
     *                                          facts — exactly what gets persisted to workflow_executions.findings and
     *                                          embedded as the DATA section of the workflow's prompt to the agent.
     */
    public function __construct(
        public bool $hasFindings,
        public array $findings,
        public string $noFindingsMessage,
    ) {}
}
