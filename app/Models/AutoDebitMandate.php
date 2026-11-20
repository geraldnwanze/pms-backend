<?php

namespace App\Models;

class AutoDebitMandate extends PmsModel
{
    protected $table = 'auto_debit_mandates';

    /** The saved card token is encrypted at rest and never serialised. */
    protected $hidden = ['authorizationCode'];

    protected $casts = [
        'authorizationCode' => 'encrypted',
    ];
}
