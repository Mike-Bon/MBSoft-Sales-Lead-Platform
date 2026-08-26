<?php

namespace App\Jobs;

use App\Contracts\Communication\EmailProvider;
use App\Contracts\Communication\WhatsAppProvider;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationFailureCode;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Support\Communication\ProviderSendResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * STEP 21 (queued execution, controlled retry) + STEP 22 (idempotency):
 * the only place a provider is actually called to send a message. Never
 * dispatched from anywhere except CommunicationService, and never
 * dispatched more than once per Communication.
 *
 * Retry policy: up to 3 attempts total, with a growing backoff, and only
 * for failures the provider itself marked retryable (rate limits,
 * transient network errors) via CommunicationFailureCode::isRetryable().
 * Anything else (auth failure, invalid recipient, a hard provider error)
 * is recorded as a permanent Failed the first time — retrying it would
 * never succeed, so we don't waste attempts or risk a duplicate send.
 */
class SendCommunicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly int $communicationId) {}

    public function handle(EmailProvider $emailProvider, WhatsAppProvider $whatsAppProvider): void
    {
        $communication = Communication::find($this->communicationId);

        if (! $communication) {
            return;
        }

        // Idempotency guard: if a previous attempt already reached a
        // terminal state (e.g. the provider accepted the message but the
        // worker crashed before this method returned, and the job was
        // retried), do not send it again.
        if ($communication->status->isTerminal() || $communication->provider_message_id !== null) {
            return;
        }

        $communication->status = CommunicationStatus::Sending;
        $communication->save();

        $result = $communication->channel === CommunicationChannel::Email
            ? $emailProvider->send(
                $communication->emailAccount,
                $communication->recipient,
                (string) $communication->subject,
                $communication->body,
                $communication->provider_thread_id,
            )
            : $whatsAppProvider->send(
                $communication->whatsAppNumber,
                $communication->recipient,
                $communication->body,
            );

        $this->applyResult($communication, $result);
    }

    private function applyResult(Communication $communication, ProviderSendResult $result): void
    {
        if ($result->success) {
            $communication->status = CommunicationStatus::Sent;
            $communication->provider_message_id = $result->providerMessageId;
            $communication->provider_thread_id = $result->providerThreadId ?? $communication->provider_thread_id;
            $communication->sent_at = now();
            $communication->metadata = $result->metadata ?: null;
            $communication->save();

            return;
        }

        $isRetryable = $result->failureCode?->isRetryable() ?? false;

        if ($isRetryable && $this->attempts() < $this->tries) {
            // Record the failure code/reason so it's visible even mid-retry,
            // but leave status at Sending -> the release() below re-queues
            // this same job (still QUEUED for the user's purposes) rather
            // than treating this attempt as final.
            $communication->failure_code = $result->failureCode;
            $communication->failure_reason = $result->failureReason;
            $communication->save();

            $this->release($this->backoff[$this->attempts() - 1] ?? 90);

            return;
        }

        $this->markFailed($communication, $result->failureCode?->value, $result->failureReason ?? 'The message could not be sent.');
    }

    private function markFailed(Communication $communication, ?string $code, string $reason): void
    {
        $communication->status = CommunicationStatus::Failed;
        $communication->failure_code = $code;
        $communication->failure_reason = $reason;
        $communication->failed_at = now();
        $communication->save();
    }

    /**
     * Last-resort handler if the job exhausts its retries because of an
     * uncaught exception (a genuine bug or an unexpected provider SDK
     * failure), rather than a normal ProviderSendResult::failure(). The
     * Communication must still end in a terminal state — never left
     * stuck at Queued/Sending forever — but the raw exception is never
     * exposed to the end user (STEP 23), only logged server-side.
     */
    public function failed(?Throwable $exception): void
    {
        $communication = Communication::find($this->communicationId);

        if (! $communication || $communication->status->isTerminal()) {
            return;
        }

        Log::error('SendCommunicationJob exhausted retries', [
            'communication_id' => $this->communicationId,
            'exception' => $exception?->getMessage(),
        ]);

        $this->markFailed(
            $communication,
            CommunicationFailureCode::ProviderError->value,
            'The message could not be sent after multiple attempts.',
        );
    }
}
