<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** `billedReading` is the meter reading the tenant has been invoiced up to; what is read beyond it is still to bill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->decimal('billedReading', 14, 3)->nullable();
            $table->string('billedAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meters', function (Blueprint $table) {
            $table->dropColumn(['billedReading', 'billedAt']);
        });
    }
};
