<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Collections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the entire dataset in exactly the shape of the frontend's Redux
 * `data` slice. The SPA calls this once after login and hydrates the store;
 * it then keeps working against local state and persists changes via /sync.
 *
 * Role-based scoping is already implemented on the client (see
 * `visiblePropertyIds` in the frontend), so this endpoint returns the full
 * dataset for every authenticated user.
 */
class BootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = [];

        foreach (Collections::MAP as $key => $modelClass) {
            $payload[$key] = $modelClass::query()->get();
        }

        return response()->json($payload);
    }
}
