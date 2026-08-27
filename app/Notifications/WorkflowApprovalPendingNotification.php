<?php

namespace App\Notifications;

use App\Models\WorkflowApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Phase 11: a Phase 8 workflow drafted a communication and is waiting on
 * this user's explicit review/send decision (never sent automatically —
 * see WorkflowExecutionService/ApprovalService). Database channel only:
 * no email/SMS side-channel, consistent with CLAUDE.md's "no autonomous
 * outbound messaging" — this notification itself never leaves the
 * application.
 *
 * `data` deliberately holds only small, non-sensitive routing fields —
 * never the drafted message body/subject (that already lives on
 * WorkflowApproval itself, reached only through its own authorized
 * `approvals.show` route).
 */
class WorkflowApprovalPendingNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly WorkflowApproval $approval) {}

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
            'kind' => 'workflow_approval_pending',
            'workflow_approval_id' => $this->approval->id,
            'channel' => $this->approval->channel->value,
            'recipient' => $this->approval->recipient,
        ];
    }
}
