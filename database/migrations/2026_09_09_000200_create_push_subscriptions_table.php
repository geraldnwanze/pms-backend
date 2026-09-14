<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push subscriptions. One row per browser/device a user has opted in from;
 * `endpoint` is the push service URL the server posts encrypted payloads to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('userId')->index();
            $table->text('endpoint');
            $table->string('publicKey');   // client p256dh key
            $table->string('authToken');   // client auth secret
            $table->string('contentEncoding')->nullable();
            $table->string('userAgent')->nullable();
            $table->string('createdAt')->nullable();
            $table->string('lastUsedAt')->nullable();

            $table->unique(['userId', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
