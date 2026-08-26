<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 gap fix (identified while implementing Phase 4's actual-sales
 * calculation): Opportunity had no field recording when it actually
 * closed — only expected_close_date, which is set at creation and not
 * updated on stage change, and so cannot reliably answer "did this deal
 * close within the target period" (STEP 8's "relevant close date").
 *
 * closed_at is set automatically by OpportunityService the moment a
 * stage transitions into Closed Won/Closed Lost (or on creation, if
 * created directly into a closed stage), and cleared if the opportunity
 * is reopened. It can also be set explicitly, to backdate a historical/
 * imported deal to its real close date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('expected_close_date');
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropIndex(['closed_at']);
            $table->dropColumn('closed_at');
        });
    }
};
