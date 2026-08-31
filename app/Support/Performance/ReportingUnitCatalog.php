<?php

namespace App\Support\Performance;

/**
 * The canonical FY2026 reporting-unit (branch) master list for operational
 * performance — 44 units across the 10 Metro Cebu Branch teams.
 *
 * Derived from the "Budget" sheet of
 * "MCEB BRANCH CORPORATE BUDGET FOR 2026 MONITORING.xlsx". Every name
 * discrepancy between the Budget sheet and the per-team actual sheets was
 * reviewed and confirmed by Mike (see docs/FY2026_WORKBOOK_MAPPING.md §3A):
 *
 *   - `name`          : the canonical Budget-sheet label (business identity)
 *   - `code`          : <TEAM_CODE>_<NORMALIZED_BUDGET_LABEL>, stable import key
 *   - `aliases`       : the differing label used on the team actual sheet,
 *                       kept for provenance only (not a DB column)
 *   - `mapping_status`: EXACT | NORMALIZED_CONFIRMED | ALIAS_CONFIRMED
 *   - `sort_order`    : Budget-sheet row order
 *
 * These are internal reporting locations. They are NEVER CRM Organizations
 * and their figures are NEVER CRM Opportunities.
 *
 * This list is master data, not seed/demo data: `performance:seed-reporting-units`
 * upserts it idempotently. Regenerate via scratchpad/extract.py if the
 * workbook structure ever changes — do not hand-edit.
 */
final class ReportingUnitCatalog
{
    /**
     * @return list<array{team_code: string, code: string, name: string, sort_order: int, aliases: string, mapping_status: string}>
     */
    public static function fy2026(): array
    {
        return [
            ['team_code' => 'CEC', 'code' => 'CEC_TABOAN', 'name' => 'TABOAN', 'sort_order' => 1, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CEC', 'code' => 'CEC_GAISANO_SOUTH', 'name' => 'GAISANO SOUTH', 'sort_order' => 2, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CEC', 'code' => 'CEC_E_MALL', 'name' => 'E MALL', 'sort_order' => 3, 'aliases' => 'E-MALL', 'mapping_status' => 'NORMALIZED_CONFIRMED'],
            ['team_code' => 'CBE', 'code' => 'CBE_METRO_AYALA_CEBU', 'name' => 'METRO AYALA CEBU', 'sort_order' => 4, 'aliases' => 'METRO AYALA', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CBE', 'code' => 'CBE_KASAMBAGAN', 'name' => 'KASAMBAGAN', 'sort_order' => 5, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CBE', 'code' => 'CBE_GORORDO', 'name' => 'GORORDO', 'sort_order' => 6, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CBE', 'code' => 'CBE_ESCARIO', 'name' => 'ESCARIO', 'sort_order' => 7, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CEN', 'code' => 'CEN_RAMOS', 'name' => 'RAMOS', 'sort_order' => 8, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CEN', 'code' => 'CEN_PACEO_ARCENAS', 'name' => 'PACEO ARCENAS', 'sort_order' => 9, 'aliases' => 'PASEO', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CEN', 'code' => 'CEN_ONE_PAVILLION', 'name' => 'ONE PAVILLION', 'sort_order' => 10, 'aliases' => 'PAVILLION', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CEN', 'code' => 'CEN_FUENTE_OSMENA', 'name' => 'FUENTE OSMENA', 'sort_order' => 11, 'aliases' => 'OSMENA', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CNE', 'code' => 'CNE_JY_SQUARE_MALL', 'name' => 'JY SQUARE MALL', 'sort_order' => 12, 'aliases' => 'JY', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CNE', 'code' => 'CNE_GAISANO_GRAND_MALL_TALAMBAN', 'name' => 'GAISANO GRAND MALL TALAMBAN', 'sort_order' => 13, 'aliases' => 'TALAMBAN', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CNE', 'code' => 'CNE_GQS', 'name' => 'GQS', 'sort_order' => 14, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CNE', 'code' => 'CNE_CENTRAL_BLOC_MALL', 'name' => 'CENTRAL BLOC MALL', 'sort_order' => 15, 'aliases' => 'CENTRAL BLOC', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CNE', 'code' => 'CNE_CABANCALAN_ROAD', 'name' => 'CABANCALAN ROAD', 'sort_order' => 16, 'aliases' => 'CABANCALAN', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CSE', 'code' => 'CSE_STO_NINO', 'name' => 'STO.NINO', 'sort_order' => 17, 'aliases' => 'STO NINO', 'mapping_status' => 'NORMALIZED_CONFIRMED'],
            ['team_code' => 'CSE', 'code' => 'CSE_SM_CEBU', 'name' => 'SM CEBU', 'sort_order' => 18, 'aliases' => 'SM CITY', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CSE', 'code' => 'CSE_ROBINSONS_GALLERIA_CEBU', 'name' => 'ROBINSONS GALLERIA CEBU', 'sort_order' => 19, 'aliases' => 'ROBINSON', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'CSE', 'code' => 'CSE_MABOLO', 'name' => 'MABOLO', 'sort_order' => 20, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CSE', 'code' => 'CSE_COLONNADE', 'name' => 'COLONNADE', 'sort_order' => 21, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CEW', 'code' => 'CEW_SM_SEASIDE', 'name' => 'SM SEASIDE', 'sort_order' => 22, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CEW', 'code' => 'CEW_SHOPWISE', 'name' => 'SHOPWISE', 'sort_order' => 23, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CEW', 'code' => 'CEW_GAISANO_TISA', 'name' => 'GAISANO TISA', 'sort_order' => 24, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'CEW', 'code' => 'CEW_BULACAO', 'name' => 'BULACAO', 'sort_order' => 25, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'MCE', 'code' => 'MCE_MARIBAGO_BRANCH', 'name' => 'MARIBAGO BRANCH', 'sort_order' => 26, 'aliases' => 'MARIBAGO', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MCE', 'code' => 'MCE_MACTAN_MARINA_MALL', 'name' => 'MACTAN MARINA MALL', 'sort_order' => 27, 'aliases' => 'MARINA MALL', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MCE', 'code' => 'MCE_GAISANO_ISLAND_MALL', 'name' => 'GAISANO ISLAND MALL', 'sort_order' => 28, 'aliases' => 'ISLAND MALL', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MCE', 'code' => 'MCE_CEBU_AIRPORT', 'name' => 'CEBU AIRPORT', 'sort_order' => 29, 'aliases' => 'AIRPORT', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MCW', 'code' => 'MCW_MEZ_II', 'name' => 'MEZ-II', 'sort_order' => 30, 'aliases' => 'MEPZ 2', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MCW', 'code' => 'MCW_LAPU_LAPU_MARKET_BRANCH', 'name' => 'LAPU LAPU MARKET BRANCH', 'sort_order' => 31, 'aliases' => 'LAPU-LAPU MARKET', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MCW', 'code' => 'MCW_GMC_PAJO', 'name' => 'GMC PAJO', 'sort_order' => 32, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'MCW', 'code' => 'MCW_GAISANO_GRAND_MALL_CORDOVA', 'name' => 'GAISANO GRAND MALL CORDOVA', 'sort_order' => 33, 'aliases' => 'GAISANO CORDOVA', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MCW', 'code' => 'MCW_GAISANO_GRAND_MALL', 'name' => 'GAISANO GRAND MALL', 'sort_order' => 34, 'aliases' => 'GAISANO BASAK', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNC', 'code' => 'MNC_NORTH_ATRIUM_MANDAUE', 'name' => 'NORTH ATRIUM MANDAUE', 'sort_order' => 35, 'aliases' => 'NORTH ATRIUM', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNC', 'code' => 'MNC_MANDAUE_RECLAMATION', 'name' => 'MANDAUE RECLAMATION', 'sort_order' => 36, 'aliases' => 'RECLAMATION', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNC', 'code' => 'MNC_MANDAUE_PARKMALL', 'name' => 'MANDAUE PARKMALL', 'sort_order' => 37, 'aliases' => 'PARKMALL', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNC', 'code' => 'MNC_ARCADA_5', 'name' => 'ARCADA 5', 'sort_order' => 38, 'aliases' => 'ARCADA', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNC', 'code' => 'MNC_AC_CORTEZ', 'name' => 'AC CORTEZ', 'sort_order' => 39, 'aliases' => 'AC CORTES', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNE', 'code' => 'MNE_PACIFIC_MALL_MANDAUE', 'name' => 'PACIFIC MALL MANDAUE', 'sort_order' => 40, 'aliases' => 'PACIFIC MALL', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNE', 'code' => 'MNE_GAISANO_CAPITAL_CASUNTINGAN', 'name' => 'GAISANO CAPITAL CASUNTINGAN', 'sort_order' => 41, 'aliases' => 'CASUNTINGAN', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNE', 'code' => 'MNE_BASAK_MANDAUE', 'name' => 'BASAK MANDAUE', 'sort_order' => 42, 'aliases' => '', 'mapping_status' => 'EXACT'],
            ['team_code' => 'MNE', 'code' => 'MNE_BANILAD_MANDAUE', 'name' => 'BANILAD MANDAUE', 'sort_order' => 43, 'aliases' => 'BANILAD', 'mapping_status' => 'ALIAS_CONFIRMED'],
            ['team_code' => 'MNE', 'code' => 'MNE_SM_J_MALL', 'name' => 'SM J MALL', 'sort_order' => 44, 'aliases' => 'SM JMALL', 'mapping_status' => 'NORMALIZED_CONFIRMED'],
        ];
    }

    public const EXPECTED_COUNT = 44;

    /**
     * The 10 team codes the catalog references, in workbook order.
     *
     * @return list<string>
     */
    public static function teamCodes(): array
    {
        return ['CEC', 'CBE', 'CEN', 'CNE', 'CSE', 'CEW', 'MCE', 'MCW', 'MNC', 'MNE'];
    }
}
