<?php

namespace App\Models;

class Message extends PmsModel
{
    protected $table = 'messages';

    protected $casts = [
        'read' => 'boolean',
    ];
}
