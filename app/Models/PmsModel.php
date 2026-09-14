<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Base class for every PMS domain entity.
 *
 * - String, non-incrementing primary keys (ids come from the domain, e.g. "prop_1").
 * - No framework timestamps; entities carry their own date fields.
 * - Mass-assignment open: the API persists whole records from a trusted,
 *   authenticated client (see SyncController).
 */
abstract class PmsModel extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, array<int, string>> */
    private static array $columnCache = [];

    /** Column names for this model's table (cached per process). */
    public static function columns(): array
    {
        $table = (new static)->getTable();

        return self::$columnCache[$table] ??= Schema::getColumnListing($table);
    }

    /**
     * Normalise a raw record from the client into a row ready for
     * insert/upsert. Every table column is emitted (missing keys become
     * null) so a batch upsert has a uniform column set — SQLite requires
     * this. Array columns are JSON-encoded; booleans coerced to integers.
     */
    public static function encodeRow(array $row): array
    {
        $casts = (new static)->getCasts();
        $out = [];

        foreach (static::columns() as $col) {
            $value = array_key_exists($col, $row) ? $row[$col] : null;
            $cast = $casts[$col] ?? null;

            if (in_array($cast, ['array', 'json', 'object', 'collection'], true)) {
                $value = $value === null ? null : json_encode($value);
            } elseif ($cast === 'boolean') {
                // Real bool binds cleanly to Postgres `boolean` and MySQL/SQLite alike.
                $value = $value === null ? null : (bool) $value;
            }

            $out[$col] = $value;
        }

        return $out;
    }
}
