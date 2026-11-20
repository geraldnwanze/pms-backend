<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** How dates are written for people (notices, messages): "3 Dec 2026", the same form the app shows on screen. */
class Dates
{
    public static function friendly(Carbon|string|null $date): string
    {
        return $date ? Carbon::parse($date)->format('j M Y') : '—';
    }
}
