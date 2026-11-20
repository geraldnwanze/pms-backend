<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every uncaught exception on an `api/*` route as the standard error
 * envelope: { "message": string, "errors": object|null }.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        [$status, $message, $errors] = $this->classify($e);

        $payload = ['message' => $message, 'errors' => $errors];

        if ($status === 500 && config('app.debug')) {
            $payload['exception'] = get_class($e);
            $payload['file'] = $e->getFile().':'.$e->getLine();
        }

        // Keep headers the exception carries (e.g. Retry-After / X-RateLimit-* on a 429).
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return response()->json($payload, $status, $headers);
    }

    /** @return array{0:int,1:string,2:?array} */
    private function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [422, 'The given data was invalid.', $e->errors()],
            $e instanceof AuthenticationException => [401, 'Unauthenticated.', null],
            $e instanceof AuthorizationException => [403, $e->getMessage() ?: 'This action is unauthorized.', null],
            $e instanceof ModelNotFoundException => [404, 'Resource not found.', null],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $e->getMessage() ?: $this->statusPhrase($e->getStatusCode()),
                null,
            ],
            default => [500, config('app.debug') ? $e->getMessage() : 'Server error.', null],
        };
    }

    private function statusPhrase(int $status): string
    {
        return match ($status) {
            403 => 'This action is unauthorized.',
            404 => 'Resource not found.',
            405 => 'Method not allowed.',
            429 => 'Too many requests.',
            default => 'Request failed.',
        };
    }
}
