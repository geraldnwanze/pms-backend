<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment a technician is owed for a completed, tenant-confirmed work order.
 * Lifecycle: requested → approved → paid  (or → rejected).
 * When marked paid a linked `expenses` row is created so the P&L stays right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_payouts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('code');
            $table->string('maintenanceId')->index();
            $table->string('propertyId')->index();
            $table->string('staffId')->index();
            $table->bigInteger('amount');
            $table->bigInteger('materialsAmount')->nullable();
            $table->bigInteger('labourAmount')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('requested'); // requested|approved|rejected|paid
            $table->string('requestedAt')->nullable();
            $table->string('approvedById')->nullable();
            $table->string('approvedAt')->nullable();
            $table->text('rejectedReason')->nullable();
            $table->string('paidById')->nullable();
            $table->string('paidAt')->nullable();
            $table->string('paymentMethod')->nullable();
            $table->string('paymentReference')->nullable();
            $table->string('expenseId')->nullable();
            $table->string('createdAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_payouts');
    }
};
