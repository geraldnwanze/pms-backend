<?php

namespace App\Models;

class Invoice extends PmsModel
{
    protected $table = 'invoices';

    protected $casts = [
        'lineItems' => 'array',
        'total' => 'integer',
        'amountPaid' => 'integer',
        'penalty' => 'integer',
        'discount' => 'integer',
    ];
}
