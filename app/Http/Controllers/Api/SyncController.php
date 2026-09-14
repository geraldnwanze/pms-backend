<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PmsModel;
use App\Support\Collections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot persistence.
 *
 * The SPA owns all business logic and mutates its local store optimistically.
 * After any change it debounces and PUTs the affected collections here; this
 * controller reconciles each one (upsert incoming rows, delete the rest) so
 * the data survives reloads and is shared between the demo accounts.
 *
 * Body: { "collections": { "invoices": [ ... ], "payments": [ ... ], ... } }
 * Only keys in Collections::SYNCABLE are honoured (users/seeded accounts are
 * never overwritten this way).
 */
class SyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $incoming = $request->input('collections', []);

        if (! is_array($incoming)) {
            return response()->json(['message' => '`collections` must be an object.'], 422);
        }

        $result = [];

        DB::transaction(function () use ($incoming, &$result) {
            foreach ($incoming as $key => $rows) {
                if (! in_array($key, Collections::SYNCABLE, true) || ! is_array($rows)) {
                    continue;
                }

                /** @var class-string<PmsModel> $model */
                $model = Collections::MAP[$key];

                $encoded = [];
                $ids = [];
                foreach ($rows as $row) {
                    if (! is_array($row) || ! isset($row['id'])) {
                        continue;
                    }
                    $encoded[] = $model::encodeRow($row);
                    $ids[] = $row['id'];
                }

                // Remove rows the client no longer has (empty list clears the collection).
                $deleteQuery = $model::query();
                if (! empty($ids)) {
                    $deleteQuery->whereNotIn('id', $ids);
                }
                $deleteQuery->delete();

                // Upsert the rest.
                $updateCols = array_values(array_diff($model::columns(), ['id']));
                foreach (array_chunk($encoded, 200) as $chunk) {
                    if ($chunk) {
                        $model::query()->upsert($chunk, ['id'], $updateCols);
                    }
                }

                $result[$key] = count($encoded);
            }
        });

        return response()->json([
            'ok' => true,
            'synced' => $result,
            'at' => now()->toIso8601String(),
        ]);
    }
}
