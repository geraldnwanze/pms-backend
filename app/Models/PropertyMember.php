<?php

namespace App\Models;

class PropertyMember extends PmsModel
{
    protected $table = 'property_members';

    protected $casts = [];

    public function property()
    {
        return $this->belongsTo(Property::class, 'propertyId');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
