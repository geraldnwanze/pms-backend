<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single browser/device Web Push subscription belonging to a user.
 */
class PushSubscription extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
