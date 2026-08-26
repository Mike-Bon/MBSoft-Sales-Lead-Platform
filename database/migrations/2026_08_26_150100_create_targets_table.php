<?php

use App\Enums\TargetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->string('target_type');

            // Required for Manager/Individual targets, null for Team
            // targets (see TargetService for the exact rule per type).
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();

            // Required for Team targets. Also denormalized onto
            // Individual targets (the owner's team at creation time) so
            // team-scoped performance queries never need a join through
            // users to find "this team's individual targets".
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();

            $table->string('period_type');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_amount', 14, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default(TargetStatus::Active->value);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('target_type');
            $table->index('owner_id');
            $table->index('team_id');
            $table->index('period_start');
            $table->index('period_end');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('targets');
    }
};
