<?php

namespace App\Mail;

/**
 * Tells the tenant a submitted payment could not be verified, with the reason.
 *
 * @property array{code:string,amount:string,reason:string,portalUrl:string} $payload
 */
class PaymentRejectedMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.payment-rejected';

    protected function subjectLine(): string
    {
        return "Payment {$this->payload['code']} needs your attention";
    }
}
