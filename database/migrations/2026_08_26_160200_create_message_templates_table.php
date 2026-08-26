<?php

use App\Enums\RecordStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('channel');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status')->default(RecordStatus::Active->value);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            // Nullable: an organisation-wide template if null, a
            // team-specific one otherwise.
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->timestamps();

            $table->index('channel');
            $table->index('status');
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
