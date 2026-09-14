<?php

namespace App\Models;

class Listing extends PmsModel
{
    protected $table = 'listings';

    protected $casts = [
        'price' => 'integer',
    ];
}
