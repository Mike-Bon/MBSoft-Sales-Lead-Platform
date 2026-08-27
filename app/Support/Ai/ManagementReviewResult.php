<?php

namespace App\Support\Ai;

use App\Enums\AgentInteractionStatus;

/**
 * STEP 35/38: the structured, combined output of the Management Review
 * orchestration — each sub-agent's result kept separate (never merged
 * into one blob) so a partial failure can be reported honestly rather
 * than silently dropped or fabricated.
 */
final readonly class ManagementReviewResult
{
    public function __construct(
        public ?AgentResponse $performance,
        public ?AgentResponse $sales,
    ) {}

    public function performanceAvailable(): bool
    {
        return $this->performance !== null && $this->performance->status !== AgentInteractionStatus::Failed;
    }

    public function salesAvailable(): bool
    {
        return $this->sales !== null && $this->sales->status !== AgentInteractionStatus::Failed;
    }

    /**
     * A plain-text combined summary for the chat transcript — clearly
     * sectioned so PERFORMANCE facts and SALES facts are never
     * conflated, and a failed sub-agent is stated honestly rather than
     * silently omitted (STEP 38: "do not fabricate its output").
     */
    public function summaryText(): string
    {
        $performanceText = $this->performanceAvailable()
            ? $this->performance->text
            : 'Performance analysis could not be retrieved.';

        $salesText = $this->salesAvailable()
            ? $this->sales->text
            : 'Sales analysis could not be retrieved.';

        return "PERFORMANCE\n{$performanceText}\n\nSALES PIPELINE\n{$salesText}";
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function toolsUsed(): array
    {
        return array_merge($this->performance?->toolsUsed ?? [], $this->sales?->toolsUsed ?? []);
    }
}
