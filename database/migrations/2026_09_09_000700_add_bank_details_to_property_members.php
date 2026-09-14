<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payout bank details, per staff membership. A person invited to two properties
 * can be paid to a different account on each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_members', function (Blueprint $table) {
            $table->string('bankName')->nullable();
            $table->string('bankAccountName')->nullable();
            $table->string('bankAccountNumber')->nullable();
            $table->string('bankCode')->nullable(); // Paystack bank code, for future transfers
        });
    }

    public function down(): void
    {
        Schema::table('property_members', function (Blueprint $table) {
            $table->dropColumn(['bankName', 'bankAccountName', 'bankAccountNumber', 'bankCode']);
        });
    }
};
