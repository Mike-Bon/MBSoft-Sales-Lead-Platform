<?php

namespace App\Services\Communication\Providers;

use App\Contracts\Communication\EmailProvider;
use App\Enums\CommunicationFailureCode;
use App\Models\EmailAccount;
use App\Support\Communication\ProviderSendResult;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail as GoogleGmail;
use Google\Service\Gmail\Message as GoogleGmailMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends outbound email through the real Gmail API (users.messages.send),
 * using the official google/apiclient SDK — never raw curl against
 * Google endpoints, and never a stored Gmail password (STEP 6/7:
 * OAuth2 access/refresh tokens only, obtained via GoogleOAuthController).
 *
 * Threading (STEP 9): Gmail groups messages into a thread when the
 * outgoing message carries In-Reply-To/References headers pointing at
 * an existing message in that thread, or when Gmail's own threadId is
 * supplied on send. This provider does the latter, passing threadId
 * through when the caller has one from a prior Communication in the
 * same conversation.
 *
 * Never logs access/refresh tokens (STEP 24) — only the account id and
 * email address appear in any log line here.
 */
class GmailEmailProvider implements EmailProvider
{
    /**
     * @param  GoogleClient|null  $client  Injected only by tests, to
     *                                     point the real google/apiclient SDK at a mocked HTTP transport
     *                                     instead of the live Gmail API (STEP 28) — production code always
     *                                     leaves this null and gets a freshly-configured client per send.
     */
    public function __construct(private readonly ?GoogleClient $client = null) {}

    public function send(EmailAccount $account, string $to, string $subject, string $body, ?string $threadId = null): ProviderSendResult
    {
        try {
            $client = $this->clientFor($account);
            $gmail = new GoogleGmail($client);

            $message = new GoogleGmailMessage;
            $message->setRaw($this->encodeMessage($account->email_address, $to, $subject, $body));

            if ($threadId !== null) {
                $message->setThreadId($threadId);
            }

            $sent = $gmail->users_messages->send('me', $message);

            return ProviderSendResult::success(
                providerMessageId: $sent->getId(),
                providerThreadId: $sent->getThreadId(),
            );
        } catch (GoogleServiceException $e) {
            Log::warning('Gmail send failed', [
                'email_account_id' => $account->id,
                'email_address' => $account->email_address,
                'status_code' => $e->getCode(),
            ]);

            return ProviderSendResult::failure(...$this->mapGoogleException($e));
        } catch (Throwable $e) {
            Log::error('Gmail send failed unexpectedly', [
                'email_account_id' => $account->id,
                'email_address' => $account->email_address,
                'exception' => $e::class,
            ]);

            return ProviderSendResult::failure(CommunicationFailureCode::EmailFailed, 'The email could not be sent.');
        }
    }

    private function clientFor(EmailAccount $account): GoogleClient
    {
        $client = $this->client ?? new GoogleClient;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        // 'created' must be supplied explicitly — Google\Client::
        // isAccessTokenExpired() defaults it to 0 when absent, which
        // makes every token look expired regardless of expires_in and
        // forces a needless (or, without a refresh_token, failing)
        // refresh on every single send.
        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->token_expires_at?->diffInSeconds(now(), true) ?? 0,
            'created' => now()->timestamp,
        ]);

        // Google's SDK refreshes transparently when the access token has
        // expired, given a refresh token — but the newly-issued access
        // token must be persisted back, or every subsequent send would
        // re-refresh needlessly and eventually the stale refresh token
        // itself could be rejected by Google.
        if ($client->isAccessTokenExpired() && $account->refresh_token) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

            if (isset($newToken['access_token'])) {
                $account->forceFill([
                    'access_token' => $newToken['access_token'],
                    'token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
                ])->save();
            }
        }

        return $client;
    }

    /**
     * RFC 2822 message, base64url-encoded per Gmail API's `raw` field
     * requirement.
     */
    private function encodeMessage(string $from, string $to, string $subject, string $body): string
    {
        $headers = implode("\r\n", [
            'From: '.$from,
            'To: '.$to,
            'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ]);

        $raw = $headers."\r\n\r\n".$body;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array{0: CommunicationFailureCode, 1: string}
     */
    private function mapGoogleException(GoogleServiceException $e): array
    {
        return match (true) {
            $e->getCode() === 401 => [CommunicationFailureCode::AuthenticationError, 'The connected Gmail account needs to be reconnected.'],
            $e->getCode() === 429 => [CommunicationFailureCode::RateLimited, 'Gmail temporarily rate-limited this account.'],
            $e->getCode() === 400 => [CommunicationFailureCode::InvalidRecipient, 'Gmail rejected the recipient address.'],
            $e->getCode() >= 500 => [CommunicationFailureCode::TemporaryNetworkError, 'Gmail reported a temporary server error.'],
            default => [CommunicationFailureCode::ProviderError, 'Gmail reported an error.'],
        };
    }
}
