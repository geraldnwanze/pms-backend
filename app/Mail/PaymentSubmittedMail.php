<?php

namespace App\Mail;

/**
 * Tells finance a tenant-submitted receipt is waiting in the verification queue.
 *
 * @property array{code:string,amount:string,tenantName:string,propertyName:string,method:string,submittedAt:string,reviewUrl:string} $payload
 */
class PaymentSubmittedMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.payment-submitted';

    protected function subjectLine(): string
    {
        return "Payment {$this->payload['code']} awaiting verification";
    }
}
