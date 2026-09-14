<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenancePayout extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'materialsAmount' => 'integer',
        'labourAmount' => 'integer',
    ];

    public function request()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenanceId');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staffId');
    }
}
