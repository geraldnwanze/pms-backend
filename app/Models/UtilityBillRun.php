<?php

namespace App\Models;

/** One property's share of a "bill all ready meters" run. */
class UtilityBillRun extends PmsModel
{
    protected $table = 'utility_bill_runs';

    protected $casts = ['invoices' => 'integer', 'total' => 'integer', 'unitsSkipped' => 'integer'];
}
