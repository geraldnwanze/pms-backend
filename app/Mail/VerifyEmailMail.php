<?php

namespace App\Mail;

/**
 * "Confirm your email address" — links to the SPA's /verify-email route.
 *
 * @property array{email:string,name:string,verifyUrl:string,expiresHours:int} $payload
 */
class VerifyEmailMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.verify-email';

    protected function subjectLine(): string
    {
        return 'Confirm your email for '.config('app.name');
    }
}
