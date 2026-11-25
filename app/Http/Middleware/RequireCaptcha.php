<?php

namespace App\Http\Middleware;

use App\Services\CaptchaService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `captcha` — when the challenge is switched on, refuse the request unless it carries a `captchaToken` that Cloudflare confirms.
 * `captcha:password` is for the sign-in route, which also serves the local-only passwordless demo login: only a request signing in with an
 * email and password has to pass the challenge (the passwordless one is refused outside local anyway).
 */
class RequireCaptcha
{
    public function __construct(private readonly CaptchaService $captcha) {}

    public function handle(Request $request, Closure $next, string $only = 'always'): Response
    {
        if (! $this->captcha->enabled() || ($only === 'password' && ! $request->filled('email'))) {
            return $next($request);
        }

        $token = $request->input('captchaToken');
        if (blank($token) || ! is_string($token)) {
            throw ValidationException::withMessages(['captchaToken' => ['Please confirm you are not a robot.']]);
        }
        if (! $this->captcha->verify($token, $request->ip())) {
            throw ValidationException::withMessages(['captchaToken' => ['We could not verify that you are not a robot. Please try again.']]);
        }

        return $next($request);
    }
}
