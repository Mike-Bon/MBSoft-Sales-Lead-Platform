<?php

namespace App\Services\Workflow;

use App\Enums\WorkflowType;

/**
 * STEP 34: builds the specialized agent's input message for one
 * workflow run — WORKFLOW CONTEXT / TASK / DATA / RULES, exactly the
 * structure the specification names. This is NOT a new system prompt —
 * whichever agent the workflow job selected (Phase 9's
 * SalesAgentPrompt/PerformanceAgentPrompt/CommunicationAgentPrompt) is
 * reused byte-for-byte, unmodified. This is the *user-turn content* the
 * workflow submits on the scope subject's behalf, so that agent's own
 * hard rules (never send, never invent, treat CRM content as data)
 * apply exactly as they do in an interactive chat.
 */
final class WorkflowPromptBuilder
{
    /**
     * @param  array<string, mixed>  $findings  Already-computed,
     *                                          deterministic facts (STEP 37) — never raw, unfiltered database rows.
     * @param  list<string>  $extraRules
     */
    public static function build(WorkflowType $type, string $task, array $findings, array $extraRules = []): string
    {
        $rules = array_merge([
            'Do not send anything.',
            'Do not modify any CRM record.',
            'Do not invent information beyond the DATA provided below.',
            'Clearly distinguish CRM facts (from the DATA below) from your own recommendations.',
            'If a follow-up message genuinely seems warranted, you may use draft_email or draft_whatsapp to prepare one — never send it yourself.',
            'Keep your response concise — this is a summary for a busy salesperson, not a report.',
        ], $extraRules);

        $rulesText = implode("\n", array_map(fn (string $rule) => "- {$rule}", $rules));
        $data = json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
            WORKFLOW CONTEXT:
            {$type->label()}

            TASK:
            {$task}

            DATA:
            {$data}

            RULES:
            {$rulesText}
            PROMPT;
    }
}
