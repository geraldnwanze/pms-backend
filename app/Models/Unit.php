<?php

namespace App\Models;

class Unit extends PmsModel
{
    protected $table = 'units';

    protected $casts = [
        'meterIds' => 'array',
        'photos' => 'array',
        'floor' => 'integer',
        'sizeSqm' => 'integer',
        'bedrooms' => 'integer',
        'bathrooms' => 'integer',
        'parkingSpaces' => 'integer',
        'rent' => 'integer',
        'serviceCharge' => 'integer',
        'securityDeposit' => 'integer',
        'utilityCharge' => 'integer',
        'otherCharges' => 'integer',
    ];
}
