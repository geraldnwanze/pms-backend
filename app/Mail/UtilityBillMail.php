<?php

namespace App\Mail;

/**
 * A utility invoice raised from meter readings: the monthly job, a "bill all" run, a single meter, or the final bill
 * when a tenancy ends.
 *
 * @property array{code:string,dueDate:string,total:string,final:bool,lines:array<int,array{description:string,amount:string}>,portalUrl:string} $payload
 */
class UtilityBillMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.utility-bill';

    protected function subjectLine(): string
    {
        return $this->payload['final']
            ? "Your final utility bill: invoice {$this->payload['code']}"
            : "Utility bill: invoice {$this->payload['code']}";
    }
}
