<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Collections;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Re-seed the demo dataset from database/data/seed-data.json. Admin only.
 * Returns the fresh dataset so the client can re-hydrate in place.
 */
class ResetController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Only an admin can reset the demo data.'], 403);
        }

        app(DatabaseSeeder::class)->run();

        $payload = [];
        foreach (Collections::MAP as $key => $modelClass) {
            $payload[$key] = $modelClass::query()->get();
        }

        return response()->json(['ok' => true, 'data' => $payload]);
    }
}
