<?php

namespace App\Contracts\Communication;

use App\Models\WhatsAppBusinessNumber;
use App\Support\Communication\ProviderSendResult;

/**
 * STEP 2: provider isolation, mirroring EmailProvider. Nothing outside
 * app/Services/Communication/Providers ever calls the WhatsApp Cloud API
 * directly.
 */
interface WhatsAppProvider
{
    /**
     * Send one outbound WhatsApp text message through the given business
     * number. Meta's Cloud API only accepts free-form text to a
     * recipient inside an open 24-hour customer service window (i.e.
     * after that recipient has messaged the business); outside that
     * window only a pre-approved message template may be sent. This
     * method sends the rendered text body as-is — respecting that
     * window is the caller's responsibility (documented limitation, see
     * docs/COMMUNICATIONS.md: full template-catalog/approval-status
     * integration is not implemented in this phase).
     */
    public function send(WhatsAppBusinessNumber $number, string $to, string $body): ProviderSendResult;
}
