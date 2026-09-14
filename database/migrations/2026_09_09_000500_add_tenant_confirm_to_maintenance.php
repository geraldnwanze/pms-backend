<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The maintenance flow gains a `tenant-confirmed` status between `completed`
 * and `verified`: the tenant confirms the work was actually done before a
 * manager verifies it and before the technician can request payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance', function (Blueprint $table) {
            $table->string('tenantConfirmedAt')->nullable();
            $table->string('tenantConfirmedById')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance', function (Blueprint $table) {
            $table->dropColumn(['tenantConfirmedAt', 'tenantConfirmedById']);
        });
    }
};
