<?php

namespace App\Mail;

/**
 * Notifies a property's managers/owners that a new work order was logged.
 *
 * @property array{code:string,title:string,category:string,urgency:string,propertyName:string,unitCode:string,boardUrl:string} $payload
 */
class MaintenanceRequestedMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.maintenance-requested';

    protected function subjectLine(): string
    {
        $tag = $this->payload['urgency'] === 'emergency' ? '[EMERGENCY] ' : '';

        return "{$tag}New maintenance request: {$this->payload['title']}";
    }
}
