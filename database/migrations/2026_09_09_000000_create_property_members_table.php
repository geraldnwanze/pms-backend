<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's relationship to one property.
 *
 * Property access is granted entirely through these rows: an owner invites an
 * email address with a role; the invitee accepts; the membership becomes
 * `active` and PropertyScope then includes that property for that user. A user
 * with no active membership sees no property data at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_members', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('propertyId')->index();
            $t->string('userId')->nullable()->index();   // null until the invite is claimed
            $t->string('email')->index();                // invite target, lower-cased
            $t->string('role');                          // owner|manager|agent|finance|maintenance|tenant
            $t->string('status')->default('invited');    // invited|active|declined|revoked
            $t->string('tenantId')->nullable();          // links a tenant-role member to a tenants row
            $t->string('invitedById')->nullable();
            $t->string('invitedAt')->nullable();
            $t->string('respondedAt')->nullable();

            $t->unique(['propertyId', 'email', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_members');
    }
};
