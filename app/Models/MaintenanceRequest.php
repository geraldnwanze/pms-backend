<?php

namespace App\Models;

class MaintenanceRequest extends PmsModel
{
    protected $table = 'maintenance';

    protected $casts = [
        'rounds' => 'array',
        'photos' => 'array',
        'afterPhotos' => 'array',
        'materialsMatch' => 'boolean',
        'materialsCost' => 'integer',
        'labourCost' => 'integer',
    ];
}
