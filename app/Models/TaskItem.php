<?php

namespace App\Models;

class TaskItem extends PmsModel
{
    protected $table = 'tasks';

    protected $casts = [
        'done' => 'boolean',
    ];
}
