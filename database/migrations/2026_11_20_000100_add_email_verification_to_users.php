<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proof of mailbox ownership. An invitation is addressed to an email, so before an account can see or accept one
 * it must have shown it controls that address. The token itself is never stored — only its SHA-256.
 *
 * Accounts that already exist are grandfathered as verified: locking everyone out on deploy would be worse than
 * the gap this closes, and the gap only mattered for addresses that had not registered yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('emailVerifiedAt')->nullable();
            $table->string('emailVerifyTokenHash')->nullable();
            $table->string('emailVerifyExpiresAt')->nullable();
        });

        DB::table('users')->update(['emailVerifiedAt' => now()->toIso8601String()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['emailVerifiedAt', 'emailVerifyTokenHash', 'emailVerifyExpiresAt']);
        });
    }
};
