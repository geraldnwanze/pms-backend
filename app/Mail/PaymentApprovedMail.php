<?php

namespace App\Mail;

/**
 * Confirms to the tenant that a submitted payment was verified and applied.
 *
 * @property array{code:string,amount:string,invoiceCode:string,balanceAfter:string,portalUrl:string} $payload
 */
class PaymentApprovedMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.payment-approved';

    protected function subjectLine(): string
    {
        return "Payment {$this->payload['code']} approved";
    }
}
