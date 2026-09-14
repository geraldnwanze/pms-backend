<?php

namespace App\Models;

class Meter extends PmsModel
{
    protected $table = 'meters';

    protected $casts = [
        'history' => 'array',
        'previousReading' => 'integer',
        'currentReading' => 'integer',
        'ratePerUnit' => 'float',
    ];
}
