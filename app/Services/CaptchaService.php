<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks the "I'm not a robot" challenge (Cloudflare Turnstile) the sign-in pages show. A token is a single-use proof from Cloudflare that the
 * visitor passed the challenge; it is only believed once Cloudflare confirms it. Anything that stops Cloudflare confirming it (no token, a
 * rejected token, Cloudflare unreachable, a missing secret) counts as a failure, so a broken setup blocks sign-in rather than silently opening it.
 */
class CaptchaService
{
    public function enabled(): bool
    {
        return (bool) config('services.turnstile.enabled');
    }

    /** The public key the page needs to draw the widget. Never the secret. */
    public function siteKey(): ?string
    {
        return $this->enabled() ? (config('services.turnstile.site_key') ?: null) : null;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if (blank($token)) {
            return false;
        }

        $secret = config('services.turnstile.secret_key');
        if (blank($secret)) {
            Log::error('Turnstile is enabled but TURNSTILE_SECRET_KEY is empty, so every challenge is refused.');

            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(config('services.turnstile.verify_url'), array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (Throwable $e) {
            Log::warning('Turnstile: could not reach Cloudflare to verify a token.', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Turnstile: Cloudflare answered with an error.', ['status' => $response->status()]);

            return false;
        }

        return $response->json('success') === true;
    }
}
