<?php

use App\Enums\WhatsAppNumberStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business-owned WhatsApp number (STEP 11), not a personal account —
 * optionally scoped to one team, or organisation-wide if team_id is
 * null. Deliberately holds no credentials of its own: the WhatsApp
 * Business Platform's System User access token and App Secret are
 * per-app (WABA-level), not per-phone-number, so they live in config/
 * services.php + .env instead (see docs/COMMUNICATIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_business_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->string('display_name');
            $table->string('phone_number');
            $table->string('phone_number_id')->unique();
            $table->string('waba_id')->nullable();
            $table->string('status')->default(WhatsAppNumberStatus::Connected->value);

            $table->timestamps();

            $table->index('team_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_business_numbers');
    }
};
