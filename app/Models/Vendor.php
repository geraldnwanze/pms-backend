<?php

namespace App\Models;

class Vendor extends PmsModel
{
    protected $table = 'vendors';

    protected $casts = [
        'totalPaid' => 'integer',
        'outstanding' => 'integer',
    ];

    /**
     * What a vendor has been paid and what they are still owed, worked out from their expenses: `paid`
     * expenses are paid, `approved` ones are owed. Called whenever an expense changes so the figures the
     * vendors page shows are never stale.
     */
    public static function refreshTotals(?string $vendorId): void
    {
        if (! $vendorId) {
            return;
        }

        $sum = fn (string $status) => (int) Expense::query()->where('vendorId', $vendorId)->where('status', $status)->sum('amount');

        static::query()->whereKey($vendorId)->update(['totalPaid' => $sum('paid'), 'outstanding' => $sum('approved')]);
    }
}
