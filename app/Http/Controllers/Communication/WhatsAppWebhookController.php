<?php

namespace App\Http\Controllers\Communication;

use App\Enums\ActivityType;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Communication;
use App\Models\Contact;
use App\Models\WhatsAppBusinessNumber;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * STEP 13/14: WhatsApp Cloud API webhook. Never trusts an inbound
 * payload without first verifying it actually came from Meta
 * (X-Hub-Signature-256, HMAC-SHA256 of the raw request body with the
 * App Secret — the exact mechanism Meta's own webhook documentation
 * specifies). Idempotent by provider message id, since Meta may resend
 * the same event on a delayed 200 response.
 *
 * This controller only ever *records* inbound events — it never sends a
 * reply of any kind (STEP 18: no autonomous messaging).
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly ActivityLogger $activities) {}

    /**
     * Meta's one-time GET handshake when a webhook URL is registered.
     */
    public function verify(Request $request): Response
    {
        $verifyToken = config('services.whatsapp.webhook_verify_token');

        if ($request->query('hub_mode') === 'subscribe'
            && $verifyToken !== null
            && hash_equals($verifyToken, (string) $request->query('hub_verify_token'))
        ) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        Log::warning('WhatsApp webhook verification failed', ['ip' => $request->ip()]);

        return response('Verification failed.', 403);
    }

    public function handle(Request $request): Response
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('WhatsApp webhook signature verification failed', ['ip' => $request->ip()]);

            return response('Invalid signature.', 403);
        }

        $payload = $request->json()->all();

        foreach (data_get($payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);

                foreach (data_get($value, 'messages', []) as $message) {
                    $this->recordInboundMessage($value, $message);
                }

                foreach (data_get($value, 'statuses', []) as $status) {
                    $this->applyStatusUpdate($status);
                }
            }
        }

        // Meta requires a 200 within a few seconds or it will retry (and
        // eventually disable) the webhook — every branch above already
        // completed its own work synchronously and cheaply, so nothing
        // here is deferred to a queue.
        return response('OK', 200);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = config('services.whatsapp.app_secret');
        $signature = $request->header('X-Hub-Signature-256');

        if (! $secret || ! $signature || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $message
     */
    private function recordInboundMessage(array $value, array $message): void
    {
        $providerMessageId = data_get($message, 'id');
        $phoneNumberId = data_get($value, 'metadata.phone_number_id');

        if (! $providerMessageId || ! $phoneNumberId) {
            return;
        }

        // Idempotency (STEP 22): Meta may deliver the same event more
        // than once (e.g. if our 200 response was delayed or dropped).
        if (Communication::where('provider', 'whatsapp_cloud_api')->where('provider_message_id', $providerMessageId)->exists()) {
            return;
        }

        $number = WhatsAppBusinessNumber::where('phone_number_id', $phoneNumberId)->first();

        if (! $number) {
            Log::warning('WhatsApp webhook message for an unregistered phone_number_id', ['phone_number_id' => $phoneNumberId]);

            return;
        }

        $from = data_get($message, 'from');
        $body = data_get($message, 'text.body') ?? '['.data_get($message, 'type', 'unsupported').' message]';

        // STEP 13: match the sender to a Contact by phone number. If no
        // match is found, the message is still recorded — contact_id/
        // user_id simply stay null, an explicit "unmatched" state
        // visible to a Manager (see CommunicationController::index's
        // unmatched filter) rather than being silently dropped.
        $contact = $this->matchContact($from);

        $communication = new Communication;
        $communication->channel = CommunicationChannel::WhatsApp;
        $communication->direction = CommunicationDirection::Inbound;
        $communication->status = CommunicationStatus::Delivered;
        $communication->provider = 'whatsapp_cloud_api';
        $communication->provider_message_id = $providerMessageId;
        $communication->whatsapp_number_id = $number->id;
        $communication->user_id = $contact?->owner_id;
        $communication->team_id = $contact?->team_id;
        $communication->organization_id = $contact?->organization_id;
        $communication->contact_id = $contact?->id;
        $communication->recipient = $number->phone_number;
        $communication->sender = $from;
        $communication->body = $body;
        $communication->sent_at = now();
        $communication->delivered_at = now();
        $communication->save();

        if ($contact !== null && $contact->owner_id !== null) {
            $owner = $contact->owner;

            if ($owner) {
                $this->activities->log($owner, ActivityType::WhatsApp, [
                    'contact_id' => $contact->id,
                    'organization_id' => $contact->organization_id,
                    'description' => 'Received: '.Str::limit($body, 100),
                    'occurred_at' => now(),
                    'communication_id' => $communication->id,
                ]);
            }
        }
    }

    /**
     * Matches purely on digits (ignoring +, spaces, dashes, parens) so
     * "+1 (555) 000-1111" and "15550001111" are recognised as the same
     * number. Done in PHP rather than a driver-specific SQL function
     * (Postgres and the SQLite test database don't share one) — compares
     * against every contact with a phone number on file, which is a
     * known scale limitation worth revisiting if the contact list grows
     * very large (see docs/COMMUNICATIONS.md).
     */
    private function matchContact(?string $fromNumber): ?Contact
    {
        if (! $fromNumber) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $fromNumber);

        return Contact::query()
            ->where(function ($query) {
                $query->whereNotNull('phone')->orWhereNotNull('mobile');
            })
            ->get(['id', 'phone', 'mobile', 'owner_id', 'team_id', 'organization_id'])
            ->first(function (Contact $contact) use ($normalized) {
                $phone = preg_replace('/\D+/', '', (string) $contact->phone);
                $mobile = preg_replace('/\D+/', '', (string) $contact->mobile);

                // A minimum-length guard avoids a short number on file
                // (or a data-entry typo) matching as a suffix of an
                // unrelated longer number.
                return (strlen($phone) >= 7 && str_ends_with($normalized, $phone))
                    || (strlen($mobile) >= 7 && str_ends_with($normalized, $mobile));
            });
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatusUpdate(array $status): void
    {
        $providerMessageId = data_get($status, 'id');
        $state = data_get($status, 'status');

        if (! $providerMessageId || ! $state) {
            return;
        }

        $communication = Communication::where('provider', 'whatsapp_cloud_api')
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if (! $communication) {
            return;
        }

        // Out-of-order delivery guard: Meta doesn't guarantee status
        // callbacks arrive in order, so a late "sent" must never
        // downgrade an already more-advanced state (delivered/read).
        if ($communication->status->isTerminal() && $state === 'sent') {
            return;
        }

        if ($state === 'sent') {
            $communication->status = CommunicationStatus::Sent;
        } elseif ($state === 'delivered') {
            $communication->status = CommunicationStatus::Delivered;
            $communication->delivered_at ??= now();
        } elseif ($state === 'read') {
            $communication->status = CommunicationStatus::Read;
            $communication->read_at ??= now();
        } elseif ($state === 'failed') {
            $communication->status = CommunicationStatus::Failed;
            $communication->failed_at ??= now();
            $communication->failure_reason = data_get($status, 'errors.0.title', 'WhatsApp reported a delivery failure.');
        } else {
            return;
        }

        $communication->save();
    }
}
