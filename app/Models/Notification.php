<?php

namespace App\Models;

class Notification extends PmsModel
{
    protected $table = 'notifications';

    protected $casts = [
        'read' => 'boolean',
    ];
}
