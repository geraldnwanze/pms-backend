<?php

namespace App\Support;

/**
 * Builds absolute links back into the SPA (config `app.frontend_url`) for use
 * in transactional emails.
 */
class FrontendUrl
{
    public static function to(string $path = '/'): string
    {
        $base = rtrim((string) config('app.frontend_url', 'http://localhost:3020'), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
