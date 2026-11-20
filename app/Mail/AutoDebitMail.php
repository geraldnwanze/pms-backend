<?php

namespace App\Mail;

/**
 * Every auto-debit message: card saved / removed, "we'll debit tomorrow", a failed attempt
 * (with the retry date), and the final "pay manually" notice. The service supplies the copy.
 *
 * @property array{
 *   subject:string, heading:string, intro:string, lines?:array<int,string>,
 *   ctaLabel:string, ctaUrl:string, footnote?:string
 * } $payload
 */
class AutoDebitMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.auto-debit';

    protected function subjectLine(): string
    {
        return $this->payload['subject'];
    }
}
