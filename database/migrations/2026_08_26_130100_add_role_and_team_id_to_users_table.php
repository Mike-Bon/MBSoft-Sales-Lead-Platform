<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(UserRole::TeamMember->value)->after('password');

            // Nullable: a Manager does not belong to any team, and a newly
            // created Team Head/Team Member may briefly exist without a team
            // before the Manager assigns one. Application-level validation
            // (StoreUserRequest/UpdateUserRequest) requires a team for
            // non-Manager roles; a database CHECK constraint enforces the
            // same rule at the Postgres level (see the following migration).
            $table->foreignId('team_id')->nullable()->after('role')->constrained('teams')->nullOnDelete();

            $table->index('role');
            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
