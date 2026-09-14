<?php

namespace App\Mail;

/**
 * Password reset link for the SPA's /reset-password route.
 *
 * @property array{email:string,resetUrl:string,expiresMinutes:int} $payload
 */
class PasswordResetMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.password-reset';

    protected function subjectLine(): string
    {
        return 'Reset your '.config('app.name').' password';
    }
}
