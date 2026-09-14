<?php

namespace App\Models;

class Payment extends PmsModel
{
    protected $table = 'payments';

    protected $casts = [
        'amount' => 'integer',
        'balanceAfter' => 'integer',
    ];
}
