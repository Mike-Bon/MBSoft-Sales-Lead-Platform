<?php

use App\Enums\OpportunityStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Nullable: not every Opportunity originates from a Lead.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->string('name');

            // No separate `status` column: OpportunityStage already
            // encodes CLOSED_WON/CLOSED_LOST as terminal stage values
            // (STEP 9), so a second status field would only risk
            // disagreeing with `stage`. See Opportunity::isClosed().
            $table->string('stage')->default(OpportunityStage::Qualification->value);

            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // A controlled application field, never LLM-calculated (STEP 9).
            $table->unsignedTinyInteger('probability')->nullable();

            $table->date('expected_close_date')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('stage');
            $table->index('organization_id');
            $table->index('contact_id');
            $table->index('lead_id');
            $table->index('owner_id');
            $table->index('team_id');
            $table->index('expected_close_date');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
