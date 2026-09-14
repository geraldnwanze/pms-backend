<?php

namespace App\Mail;

/**
 * Finance has asked the tenant for more detail before verifying a payment.
 *
 * @property array{code:string,note:string,portalUrl:string} $payload
 */
class PaymentInfoRequestedMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.payment-info-requested';

    protected function subjectLine(): string
    {
        return "More information needed for payment {$this->payload['code']}";
    }
}
