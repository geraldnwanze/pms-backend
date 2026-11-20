<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vendor "total paid" / "outstanding" were typed in once and never kept up as expenses moved through
 * approval and payment. They are now maintained as expenses change; this brings the stored figures in
 * line with the expenses that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('vendors')->pluck('id') as $id) {
            $sum = fn (string $status) => (int) DB::table('expenses')->where('vendorId', $id)->where('status', $status)->sum('amount');

            DB::table('vendors')->where('id', $id)->update(['totalPaid' => $sum('paid'), 'outstanding' => $sum('approved')]);
        }
    }

    public function down(): void
    {
        // the previous figures were hand-entered and cannot be reconstructed
    }
};
