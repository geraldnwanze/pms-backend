<?php

namespace App\Models;

class Lease extends PmsModel
{
    protected $table = 'leases';

    protected $casts = [
        'rent' => 'integer',
        'securityDeposit' => 'integer',
        'noticePeriodDays' => 'integer',
        'signed' => 'boolean',
    ];
}
