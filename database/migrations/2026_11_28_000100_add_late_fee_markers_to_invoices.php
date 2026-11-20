<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A late fee is charged once per invoice. These record when it was applied, or waived, so it is never charged
 * twice and a waived fee is never put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('lateFeeAppliedOn')->nullable();
            $table->string('lateFeeWaivedOn')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['lateFeeAppliedOn', 'lateFeeWaivedOn']);
        });
    }
};
