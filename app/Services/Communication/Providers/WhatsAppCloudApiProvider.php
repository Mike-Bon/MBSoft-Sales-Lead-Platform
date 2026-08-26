<?php

namespace App\Services\Communication\Providers;

use App\Contracts\Communication\WhatsAppProvider;
use App\Enums\CommunicationFailureCode;
use App\Models\WhatsAppBusinessNumber;
use App\Support\Communication\ProviderSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends outbound WhatsApp messages through the official WhatsApp
 * Business Platform Cloud API (STEP 10) — plain HTTPS via Laravel's
 * Http facade, which is the documented integration method; no unofficial
 * SDK exists. Explicitly NOT: WhatsApp Web automation, browser
 * automation, or any personal-account library.
 *
 * The access token and app secret are per-app (WABA-level) System User
 * credentials, not per-number, so they come from config/services.php
 * (see WhatsAppBusinessNumber's docblock) rather than the number record
 * itself.
 */
class WhatsAppCloudApiProvider implements WhatsAppProvider
{
    public function send(WhatsAppBusinessNumber $number, string $to, string $body): ProviderSendResult
    {
        $apiVersion = config('services.whatsapp.api_version', 'v20.0');
        $token = config('services.whatsapp.access_token');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$apiVersion}/{$number->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $body],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('WhatsApp send failed: connection error', [
                'whatsapp_number_id' => $number->id,
            ]);

            return ProviderSendResult::failure(CommunicationFailureCode::TemporaryNetworkError, 'Could not reach the WhatsApp API.');
        }

        if ($response->successful()) {
            $messageId = data_get($response->json(), 'messages.0.id');

            return ProviderSendResult::success(providerMessageId: $messageId);
        }

        Log::warning('WhatsApp send failed', [
            'whatsapp_number_id' => $number->id,
            'status' => $response->status(),
        ]);

        return ProviderSendResult::failure(...$this->mapErrorResponse($response));
    }

    /**
     * @return array{0: CommunicationFailureCode, 1: string}
     */
    private function mapErrorResponse(Response $response): array
    {
        $errorCode = data_get($response->json(), 'error.code');
        $status = $response->status();

        return match (true) {
            $status === 401 || $errorCode === 190 => [CommunicationFailureCode::AuthenticationError, 'The WhatsApp business number needs to be reconnected.'],
            $status === 429 || $errorCode === 80007 => [CommunicationFailureCode::RateLimited, 'WhatsApp temporarily rate-limited this number.'],
            $errorCode === 131026 || $errorCode === 131047 => [CommunicationFailureCode::InvalidRecipient, 'WhatsApp rejected the recipient number.'],
            $status >= 500 => [CommunicationFailureCode::TemporaryNetworkError, 'WhatsApp reported a temporary server error.'],
            default => [CommunicationFailureCode::WhatsAppFailed, 'WhatsApp reported an error.'],
        };
    }
}
