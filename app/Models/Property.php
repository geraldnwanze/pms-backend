<?php

namespace App\Models;

class Property extends PmsModel
{
    protected $table = 'properties';

    protected $casts = [
        'owners' => 'array',
        'assignedStaffIds' => 'array',
        'amenities' => 'array',
        'photos' => 'array',
        'lat' => 'float',
        'lng' => 'float',
        'landSizeSqm' => 'integer',
        'builtUpAreaSqm' => 'integer',
        'yearBuilt' => 'integer',
        'acquisitionValue' => 'integer',
        'estimatedValue' => 'integer',
    ];
}
