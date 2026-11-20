<?php

namespace App\Models;

class AutoDebitSetup extends PmsModel
{
    protected $table = 'auto_debit_setups';

    protected $casts = [
        'amount' => 'integer',
    ];
}
