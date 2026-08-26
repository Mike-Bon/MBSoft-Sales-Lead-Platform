<?php

namespace App\Services\Dashboard;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * STEP 26: simple communication metrics for the existing dashboards —
 * emails sent, WhatsApp messages sent, total communications, and failed
 * communications, all for the given period. Deliberately minimal (no new
 * charts, no redesign) and, like CrmMetricsService, takes an
 * already-scoped Builder — the caller (ManagerDashboardService/
 * TeamDashboardService/IndividualDashboardService) decides the
 * authorized organisation/team/individual scope.
 */
class CommunicationMetricsService
{
    /**
     * @return array{emails_sent: int, whatsapp_sent: int, total: int, failed: int}
     */
    public function summary(Builder $communications, Carbon $periodStart, Carbon $periodEnd): array
    {
        $outboundInPeriod = (clone $communications)
            ->where('direction', CommunicationDirection::Outbound->value)
            ->whereBetween('created_at', [$periodStart, $periodEnd]);

        return [
            'emails_sent' => (clone $outboundInPeriod)->where('channel', CommunicationChannel::Email->value)->where('status', '!=', CommunicationStatus::Failed->value)->count(),
            'whatsapp_sent' => (clone $outboundInPeriod)->where('channel', CommunicationChannel::WhatsApp->value)->where('status', '!=', CommunicationStatus::Failed->value)->count(),
            'total' => (clone $outboundInPeriod)->count(),
            'failed' => (clone $outboundInPeriod)->where('status', CommunicationStatus::Failed->value)->count(),
        ];
    }
}
