<?php

namespace App\Mail;

/**
 * A technician-payout event (requested → approved / rejected → paid). The
 * calling service supplies the copy.
 *
 * @property array{
 *   subject:string, heading:string, intro:string,
 *   code:string, amount:string, workOrderCode:string, staffName:string,
 *   ctaLabel:string, ctaUrl:string, note?:string, reason?:string
 * } $payload
 */
class MaintenancePayoutMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.maintenance-payout';

    protected function subjectLine(): string
    {
        return $this->payload['subject'];
    }
}
