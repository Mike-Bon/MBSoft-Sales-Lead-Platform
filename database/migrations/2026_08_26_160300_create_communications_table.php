<?php

use App\Enums\CommunicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The actual external message (STEP 3) — distinct from Activity (Phase
 * 3), which stays the lightweight, immutable CRM timeline fact.
 * Sending/receiving a Communication also writes a corresponding
 * Activity entry (via CommunicationService) so the existing timeline UI
 * keeps working unchanged; activities.communication_id (added in the
 * next migration) links the two without duplicating the message body
 * into Activity's plain-text description.
 *
 * Not fully immutable like Activity: the core content (channel,
 * direction, recipient, body) never changes after creation, but status/
 * provider_message_id/delivered_at/read_at/failed_at mutate as
 * SendCommunicationJob and inbound webhooks update the record's
 * lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();

            $table->string('channel');
            $table->string('direction');
            $table->string('status')->default(CommunicationStatus::Queued->value);
            $table->string('provider');
            $table->string('provider_message_id')->nullable();
            $table->string('provider_thread_id')->nullable();

            $table->foreignId('email_account_id')->nullable()->constrained('email_accounts')->nullOnDelete();
            $table->foreignId('whatsapp_number_id')->nullable()->constrained('whatsapp_business_numbers')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('message_templates')->nullOnDelete();

            // Who initiated (outbound), or who an inbound message is
            // attributed to once matched to an owned CRM record — null
            // for a genuinely unmatched inbound message (STEP 13: never
            // silently discard one just because there's no CRM owner to
            // attribute it to). Restrict, not cascade: a user cannot be
            // deleted while their communication history exists.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();

            $table->string('subject')->nullable();
            $table->string('recipient');
            $table->string('sender');
            $table->text('body');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_reason')->nullable();

            // Raw provider response/webhook payload for debugging —
            // never contains secrets (see CommunicationService, which
            // strips tokens before persisting).
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('channel');
            $table->index('direction');
            $table->index('status');
            $table->index('provider_message_id');
            $table->index('provider_thread_id');
            $table->index('user_id');
            $table->index('team_id');
            $table->index('organization_id');
            $table->index('contact_id');
            $table->index('lead_id');
            $table->index('opportunity_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
