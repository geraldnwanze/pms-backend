<?php

namespace App\Models;

class DocumentRecord extends PmsModel
{
    protected $table = 'documents';

    protected $casts = [
        'sizeKb' => 'integer',
    ];
}
