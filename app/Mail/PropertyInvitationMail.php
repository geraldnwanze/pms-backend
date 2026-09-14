<?php

namespace App\Mail;

/**
 * Sent to an email address invited onto a property team. The recipient may not
 * have an account yet — the template tells them to register with this address.
 *
 * @property array{email:string,propertyName:string,role:string,inviterName:string,acceptUrl:string} $payload
 */
class PropertyInvitationMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.invitation';

    protected function subjectLine(): string
    {
        return "You've been invited to {$this->payload['propertyName']} on ".config('app.name');
    }
}
