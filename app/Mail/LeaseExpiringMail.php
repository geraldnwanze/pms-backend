<?php

namespace App\Mail;

/**
 * Reminds a tenant that their lease is approaching its end date.
 *
 * @property array{code:string,endDate:string,daysLeft:int,propertyName:string,unitCode:string,portalUrl:string} $payload
 */
class LeaseExpiringMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.lease-expiring';

    protected function subjectLine(): string
    {
        return "Your lease {$this->payload['code']} expires in {$this->payload['daysLeft']} days";
    }
}
