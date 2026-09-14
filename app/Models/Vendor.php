<?php

namespace App\Models;

class Vendor extends PmsModel
{
    protected $table = 'vendors';

    protected $casts = [
        'totalPaid' => 'integer',
        'outstanding' => 'integer',
    ];
}
