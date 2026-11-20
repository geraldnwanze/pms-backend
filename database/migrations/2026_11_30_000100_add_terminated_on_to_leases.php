<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a lease was terminated. Until now only its status changed, and its `endDate` stayed at the contract's end — so
 * nothing said when the tenant actually left, which the final utility bill needs (use after that day is not theirs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->string('terminatedOn')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('terminatedOn');
        });
    }
};
