<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for every transactional email in the app.
 *
 * - `ShouldQueue` → each message is pushed onto the `database` queue and sent
 *   by a `queue:work` worker, never inline with the web request.
 * - Subclasses set `$view` (a Blade markdown template under `emails.*`) and
 *   pass their template data as a plain array to the constructor, so the
 *   serialized job payload stays small and stable.
 */
abstract class QueuedMarkdownMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** A mail server hiccup should not lose the message: three attempts, a pause between them, none left hanging. */
    public $tries = 3;

    public $backoff = [30, 120];

    public $timeout = 30;

    /** Markdown view, e.g. `emails.invitation`. Set by the subclass. */
    protected string $template;

    /** @param  array<string,mixed>  $payload */
    public function __construct(protected array $payload = [])
    {
        //
    }

    abstract protected function subjectLine(): string;

    public function build(): static
    {
        return $this->subject($this->subjectLine())
            ->markdown($this->template, $this->payload + [
                'appName' => config('app.name'),
            ]);
    }

    /** Called once every attempt has failed. Leaves a line in the log; the job itself stays in `failed_jobs`. */
    public function failed(Throwable $e): void
    {
        Log::error('Queued email gave up: '.static::class.' — '.$e->getMessage());
    }
}
