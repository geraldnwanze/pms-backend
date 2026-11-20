<?php

namespace App\Models;

class Meter extends PmsModel
{
    protected $table = 'meters';

    protected $casts = [
        'history' => 'array',
        'previousReading' => 'float',
        'currentReading' => 'float',
        'billedReading' => 'float',
        'ratePerUnit' => 'float',
    ];

    protected $appends = ['unbilledUnits', 'unbilledAmount'];

    /** The reading the tenant has been billed up to (a meter never billed starts from its previous reading). */
    public function billedUpTo(): ?float
    {
        return $this->billedReading ?? $this->previousReading ?? $this->currentReading;
    }

    /** Units read but not yet invoiced (never negative; a reading that went backwards is for a person to look at). */
    public function getUnbilledUnitsAttribute(): float
    {
        if ($this->currentReading === null) {
            return 0.0;
        }

        return round(max(0, $this->currentReading - (float) $this->billedUpTo()), 3);
    }

    public function getUnbilledAmountAttribute(): int
    {
        return (int) round($this->unbilledUnits * (float) $this->ratePerUnit);
    }
}
