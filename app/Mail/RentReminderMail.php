<?php

namespace App\Mail;

/**
 * Rent due-soon / overdue reminder for a tenant, dispatched by the daily
 * `pms:rent-reminders` command.
 *
 * @property array{code:string,dueDate:string,outstanding:string,overdue:bool,days:int,portalUrl:string} $payload
 */
class RentReminderMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.rent-reminder';

    protected function subjectLine(): string
    {
        return $this->payload['overdue']
            ? "Rent overdue: invoice {$this->payload['code']}"
            : "Rent due soon: invoice {$this->payload['code']}";
    }
}
