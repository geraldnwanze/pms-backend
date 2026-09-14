<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PmsModel;
use App\Support\Collections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Conventional REST access to a single collection. Not used by the SPA (which
 * goes through /bootstrap + /sync) but useful for scripts and future clients.
 * The collection is injected as a route default (see routes/api.php).
 */
class EntityController extends Controller
{
    /** @return class-string<PmsModel> */
    private function model(Request $request): string
    {
        $collection = $request->route('collection');

        abort_unless(isset(Collections::MAP[$collection]), 404, 'Unknown collection.');

        return Collections::MAP[$collection];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->model($request)::query()->get());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->model($request)::query()->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $model = $this->model($request);
        $data = $request->all();
        $data['id'] ??= Str::lower($request->route('collection').'_'.Str::random(9));

        // Keep only real columns; Eloquent casts handle array/bool encoding.
        $data = array_intersect_key($data, array_flip($model::columns()));

        $record = $model::query()->create($data);

        return response()->json($record->fresh(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $model = $this->model($request);
        $record = $model::query()->findOrFail($id);
        $data = array_intersect_key($request->all(), array_flip($model::columns()));
        $record->fill($data)->save();

        return response()->json($record->fresh());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->model($request)::query()->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}
