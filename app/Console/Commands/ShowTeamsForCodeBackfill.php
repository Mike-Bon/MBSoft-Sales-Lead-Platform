<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Prints every team with its id, name, current `code`, and its
 * assigned Team Head (id / name / email) so a human can map each production
 * team to a workbook code (CEC, CBE, CEN, CNE, CSE, CEW, MCE, MCW, MNC, MNE)
 * BEFORE `teams.code` is backfilled.
 *
 * Match on team name + Team Head identity — never on database row order.
 * Workbook "Responsible TH" references (spellings may differ from the CRM
 * user record):
 *
 *   CEC → MARTIN MELGAR       CBE → NEISHEM PORRAS
 *   CEN → LEZ BERYL SANDE     CNE → CHERYL BRIZO
 *   CSE → LEAH MAE MOBE       CEW → MARIETTA MELGAR
 *   MCE → MELISSA YRAT        MCW → REXEL RAY TENEJEROS
 *   MNC → GINA ROSE MENDOZA   MNE → BRYLE JAY LIM
 *
 * This command writes nothing.
 */
class ShowTeamsForCodeBackfill extends Command
{
    protected $signature = 'performance:show-teams';

    protected $description = 'READ-ONLY: list teams + Team Heads to verify before the FY2026 teams.code backfill.';

    public function handle(): int
    {
        $teams = Team::query()->with('teamHead:id,name,email')->orderBy('id')->get();

        $this->line('team_id | current_code | team_name | team_head_id | team_head_name | team_head_email');
        $this->line(str_repeat('-', 90));
        foreach ($teams as $t) {
            $this->line(implode(' | ', [
                $t->id,
                $t->code ?? '(null)',
                $t->name,
                $t->teamHead?->id ?? '-',
                $t->teamHead?->name ?? '-',
                $t->teamHead?->email ?? '-',
            ]));
        }

        $this->newLine();
        $this->line('Verify each row against the workbook "Responsible TH", then run the backfill');
        $this->line('(one UPDATE per team, matched by id). This command changed nothing.');

        return self::SUCCESS;
    }
}
