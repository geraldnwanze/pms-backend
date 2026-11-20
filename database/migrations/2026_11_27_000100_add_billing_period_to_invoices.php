<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic invoicing records which billing period each invoice covers, and the database itself refuses a
 * second invoice for the same lease and period — so a re-run, or two runs at once, can never bill twice.
 * Invoices raised by hand carry no period and are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('periodStart')->nullable();
            $table->string('periodEnd')->nullable();
        });

        $g = DB::connection()->getQueryGrammar();
        DB::statement(sprintf(
            'create unique index invoices_lease_period_unique on %s (%s, %s) where %s is not null',
            $g->wrapTable('invoices'), $g->wrap('leaseId'), $g->wrap('periodStart'), $g->wrap('periodStart'),
        ));
    }

    public function down(): void
    {
        DB::statement('drop index if exists invoices_lease_period_unique');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['periodStart', 'periodEnd']);
        });
    }
};
