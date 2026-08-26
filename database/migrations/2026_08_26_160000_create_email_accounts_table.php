<?php

use App\Enums\EmailAccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One connected Gmail account per user (STEP 7 — "User 1 -> Gmail
 * account 1", not one shared org-wide inbox). access_token/refresh_token
 * are stored as Laravel `encrypted` casts on the model — see
 * App\Models\EmailAccount for the exact encryption strategy (STEP 24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('email_address');

            // Encrypted at the application layer (Model::casts() =>
            // 'encrypted'), never stored or logged in plain text.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->string('status')->default(EmailAccountStatus::Connected->value);
            $table->string('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
