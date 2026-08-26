<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an Activity timeline entry back to the Communication it
 * represents, when it represents one (STEP 15: communication events
 * must be clearly distinguishable from ordinary internal activities).
 * Null for every Activity type created before this phase, and for any
 * manually-logged Call/Meeting/Note going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('communication_id')->nullable()->after('opportunity_id')->constrained('communications')->nullOnDelete();
            $table->index('communication_id');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('communication_id');
        });
    }
};
