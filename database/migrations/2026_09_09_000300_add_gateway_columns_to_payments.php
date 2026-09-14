<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-gateway fields for online payments (Paystack). `status` gains two
 * more string values used only by gateway payments: `initiated` (checkout
 * started, not yet paid) and `failed` (gateway reported failure/abandon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway')->nullable();          // e.g. "paystack"
            $table->string('gatewayReference')->nullable();  // provider transaction reference
            $table->string('gatewayStatus')->nullable();     // provider's own status string
            $table->string('channel')->nullable();           // card / bank / ussd ...
            $table->bigInteger('feesAmount')->nullable();     // provider fees, in kobo->naira
            $table->string('paidAt')->nullable();

            $table->index('gatewayReference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gatewayReference']);
            $table->dropColumn(['gateway', 'gatewayReference', 'gatewayStatus', 'channel', 'feesAmount', 'paidAt']);
        });
    }
};
