<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interior photos of a building block — a JSON list of stored image URLs
 * (see App\Support\ImageStore). Nullable so existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->jsonb('photos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('photos');
        });
    }
};
