<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The structural style of a building block — a detached duplex, a terrace of
 * duplexes, a block of flats, a storey building, etc. Free text (the SPA
 * offers a picklist); nullable so existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->string('buildingType')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('buildingType');
        });
    }
};
