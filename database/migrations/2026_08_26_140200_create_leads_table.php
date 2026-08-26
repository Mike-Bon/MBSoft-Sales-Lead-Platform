<?php

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // A lead always has one accountable owner (CLAUDE.md: "a
            // lead/opportunity belongs to one accountable owner at a
            // time"). restrict, not cascade/null: an owner cannot be
            // deleted out from under an active lead.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            // Nullable so a Manager can hold an organisation-wide/personal
            // lead not tied to any specific team, matching their nullable
            // team_id on the users table.
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->string('source')->nullable();
            $table->string('status')->default(LeadStatus::New->value);
            $table->string('priority')->default(LeadPriority::Medium->value);
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index('next_follow_up_at');
            $table->index('organization_id');
            $table->index('contact_id');
            $table->index('owner_id');
            $table->index('team_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
