<?php

namespace App\Models;

class Building extends PmsModel
{
    protected $table = 'buildings';

    protected $casts = [
        'floors' => 'integer',
        'photos' => 'array',
    ];
}
