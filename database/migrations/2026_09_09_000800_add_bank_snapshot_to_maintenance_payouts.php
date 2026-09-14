<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of the technician's payout bank details (from their property
 * membership) taken when the payout is requested, so it stays stable even if
 * the membership's bank details change afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_payouts', function (Blueprint $table) {
            $table->string('bankName')->nullable();
            $table->string('bankAccountName')->nullable();
            $table->string('bankAccountNumber')->nullable();
            $table->string('bankCode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_payouts', function (Blueprint $table) {
            $table->dropColumn(['bankName', 'bankAccountName', 'bankAccountNumber', 'bankCode']);
        });
    }
};
