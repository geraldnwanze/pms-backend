<?php

namespace App\Models;

class Expense extends PmsModel
{
    protected $table = 'expenses';

    protected $casts = [
        'amount' => 'integer',
    ];
}
