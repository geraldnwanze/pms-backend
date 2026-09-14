<?php

namespace App\Mail;

/**
 * A single work-order status change (assigned → in-progress → completed →
 * tenant-confirmed → verified → closed). The calling service supplies the copy.
 *
 * @property array{
 *   subject:string, heading:string, intro:string, statusLabel:string,
 *   code:string, title:string, propertyName:string, unitCode:?string,
 *   ctaLabel:string, ctaUrl:string, footnote?:string
 * } $payload
 */
class MaintenanceUpdateMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.maintenance-update';

    protected function subjectLine(): string
    {
        return $this->payload['subject'];
    }
}
