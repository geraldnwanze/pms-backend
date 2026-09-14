<?php

namespace App\Http\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consistent success envelope for the v1 API:
 *
 *   { "data": <payload|null>, "message": <string|null>, "meta": <object|null> }
 *
 * Errors are shaped by the exception handler in bootstrap/app.php as
 *   { "message": <string>, "errors": <object|null> }
 */
trait ApiResponses
{
    protected function ok(mixed $data = null, ?string $message = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data instanceof JsonResource ? $data->resolve() : $data,
            'message' => $message,
            'meta' => $meta,
        ], $status);
    }

    protected function created(mixed $data = null, ?string $message = 'Created.'): JsonResponse
    {
        return $this->ok($data, $message, null, 201);
    }

    protected function message(string $message, int $status = 200): JsonResponse
    {
        return $this->ok(null, $message, null, $status);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Wrap a paginator: items go in `data`, pagination lives in `meta`.
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resource, ?string $message = null): JsonResponse
    {
        /** @var ResourceCollection $collection */
        $collection = $resource::collection($paginator);

        return response()->json([
            'data' => $collection->resolve(),
            'message' => $message,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}
