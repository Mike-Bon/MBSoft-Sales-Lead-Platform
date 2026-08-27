<?php

namespace App\Notifications;

use App\Models\Communication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Phase 11: an outbound email/WhatsApp message this user sent could not
 * be delivered (SendCommunicationJob::markFailed, after retries are
 * exhausted or the provider returned a non-retryable error). Database
 * channel only — see WorkflowApprovalPendingNotification's docblock for
 * why no email/SMS side-channel is used here.
 *
 * `data` holds only routing fields and the provider's own
 * already-user-safe failure reason (CommunicationFailureCode/
 * SendCommunicationJob never store a raw provider exception there) —
 * never the message body.
 */
class CommunicationFailedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Communication $communication) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'communication_failed',
            'communication_id' => $this->communication->id,
            'channel' => $this->communication->channel->value,
            'recipient' => $this->communication->recipient,
            'failure_reason' => $this->communication->failure_reason,
        ];
    }
}
