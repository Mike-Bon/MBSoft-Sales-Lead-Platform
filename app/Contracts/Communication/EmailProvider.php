<?php

namespace App\Contracts\Communication;

use App\Models\EmailAccount;
use App\Support\Communication\ProviderSendResult;

/**
 * STEP 2: provider isolation. Nothing outside app/Services/Communication/
 * Providers ever calls the Gmail API directly — CommunicationService and
 * SendCommunicationJob depend only on this interface, so controllers
 * never touch a provider SDK and a future provider swap needs no
 * changes above this boundary.
 */
interface EmailProvider
{
    /**
     * Send one outbound email through the given connected account.
     *
     * @param  string|null  $threadId  An existing Gmail thread id to
     *                                 reply within (STEP 9 threading), or null to start a new thread.
     */
    public function send(EmailAccount $account, string $to, string $subject, string $body, ?string $threadId = null): ProviderSendResult;
}
