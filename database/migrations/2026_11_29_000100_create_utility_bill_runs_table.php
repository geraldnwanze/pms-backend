<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per property that a "bill all ready meters" run billed: who ran it, how many invoices, how much.
 * It feeds the "recent bulk runs" list for the people who can bill — managers cannot read the audit trail, and
 * the trail is free text, so the figures are kept here as numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_bill_runs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('propertyId')->index();
            $table->string('userId');
            $table->unsignedInteger('invoices');
            $table->unsignedBigInteger('total');
            $table->unsignedInteger('unitsSkipped')->default(0);
            $table->string('createdAt')->index();      // ISO 8601, like the audit trail's `timestamp`
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_bill_runs');
    }
};
