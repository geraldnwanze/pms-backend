<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\EntityRequest;
use App\Http\Resources\EntityResource;
use App\Services\CrudService;
use App\Support\EntityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST entry point for every resource in EntityRegistry. Thin: it resolves the
 * resource's service from the container and delegates. All rules, scoping and
 * business logic live in the service (and its policy).
 */
class EntityController extends Controller
{
    use ApiResponses;

    private function service(Request $request): CrudService
    {
        $key = $request->route('resource');

        abort_unless(EntityRegistry::has($key), 404, 'Unknown resource.');

        return app(EntityRegistry::get($key)['service']);
    }

    private function label(Request $request): string
    {
        return str($request->route('resource'))->replace('-', ' ')->singular()->title();
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->service($request)->paginate($request->user(), $request->query());

        return $this->paginated($paginator, EntityResource::class, $this->label($request).' list retrieved.');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service($request)->find($request->user(), $id);

        return $this->ok(new EntityResource($record), $this->label($request).' retrieved.');
    }

    /**
     * Client-owned metadata the SPA generates locally (id, code, timestamps)
     * is honoured on create so optimistic UI stays consistent; the service
     * falls back to generating any of these that are absent.
     */
    private const CLIENT_META = ['id', 'code', 'createdAt', 'submittedAt', 'publishedAt', 'uploadedAt', 'uploadedById', 'createdById'];

    public function store(EntityRequest $request): JsonResponse
    {
        $meta = array_filter(
            $request->only(self::CLIENT_META),
            fn ($v) => $v !== null && $v !== '',
        );

        $record = $this->service($request)->create($request->user(), $meta + $request->validated());

        return $this->created(new EntityResource($record), $this->label($request).' created.');
    }

    public function update(EntityRequest $request, string $id): JsonResponse
    {
        $record = $this->service($request)->update($request->user(), $id, $request->validated());

        return $this->ok(new EntityResource($record), $this->label($request).' updated.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service($request)->delete($request->user(), $id);

        return $this->message($this->label($request).' deleted.');
    }
}
