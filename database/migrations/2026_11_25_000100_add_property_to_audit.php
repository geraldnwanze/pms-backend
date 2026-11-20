<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit rows were global, so anyone allowed to read the trail (finance) read every property's.
 * Each row now records the property it concerns, and existing rows are backfilled from their record.
 */
return new class extends Migration
{
    /** audit.entity → the table holding that record (and so its propertyId). */
    private const SOURCES = [
        'Invoice' => 'invoices', 'Lease' => 'leases', 'Maintenance' => 'maintenance', 'MaintenancePayout' => 'maintenance_payouts',
        'Expense' => 'expenses', 'Payment' => 'payments', 'PropertyMember' => 'property_members',
    ];

    public function up(): void
    {
        Schema::table('audit', function (Blueprint $table) {
            $table->string('propertyId')->nullable()->index();
        });

        $grammar = DB::connection()->getQueryGrammar();
        $col = $grammar->wrap('propertyId');
        $entityId = $grammar->wrap('entityId');

        foreach (self::SOURCES as $entity => $source) {
            if (! Schema::hasTable($source)) {
                continue;
            }
            $table = $grammar->wrapTable($source);
            DB::table('audit')->where('entity', $entity)->whereNull('propertyId')->update([
                'propertyId' => DB::raw("(select {$table}.{$col} from {$table} where {$table}.".$grammar->wrap('id')." = audit.{$entityId})"),
            ]);
        }

        DB::table('audit')->where('entity', 'Property')->whereNull('propertyId')->update(['propertyId' => DB::raw($entityId)]);
    }

    public function down(): void
    {
        Schema::table('audit', function (Blueprint $table) {
            $table->dropIndex(['propertyId']);
        });
        Schema::table('audit', function (Blueprint $table) {
            $table->dropColumn('propertyId');
        });
    }
};
