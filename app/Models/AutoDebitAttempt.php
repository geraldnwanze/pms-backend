<?php

namespace App\Models;

class AutoDebitAttempt extends PmsModel
{
    protected $table = 'auto_debit_attempts';

    protected $casts = [
        'attemptNo' => 'integer',
        'amount' => 'integer',
    ];
}
