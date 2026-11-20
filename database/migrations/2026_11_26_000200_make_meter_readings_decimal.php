<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meter readings were whole-number columns, so a reading of 235.5 m³ was stored as 235 and the amount
 * used (and billed) between two readings was wrong. Readings now keep three decimal places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->decimal('previousReading', 14, 3)->nullable()->change();
            $table->decimal('currentReading', 14, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->bigInteger('previousReading')->nullable()->change();
            $table->bigInteger('currentReading')->nullable()->change();
        });
    }
};
