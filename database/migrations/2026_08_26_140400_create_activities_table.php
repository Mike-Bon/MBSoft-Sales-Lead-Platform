<?php

use App\Enums\ActivityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();

            // Who logged it. Activities are immutable facts (CLAUDE.md:
            // "do not overwrite history to represent a later event") —
            // restrict, not cascade: a user cannot be deleted while their
            // recorded activities still exist.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Denormalized from the acting user's team at creation time,
            // for fast team-scoped timeline/index queries without a join.
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();

            $table->string('type')->default(ActivityType::Note->value);
            $table->string('subject')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index('type');
            $table->index('user_id');
            $table->index('team_id');
            $table->index('organization_id');
            $table->index('contact_id');
            $table->index('lead_id');
            $table->index('opportunity_id');
            $table->index('occurred_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
