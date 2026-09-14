<?php

namespace App\Models;

class Inspection extends PmsModel
{
    protected $table = 'inspections';

    protected $casts = [
        'items' => 'array',
        'depositDeductions' => 'integer',
    ];
}
