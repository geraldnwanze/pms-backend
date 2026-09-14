<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    use ApiResponses;

    public function __construct(private MaintenanceService $maintenance) {}

    /** Tenant confirms the technician's work was done. */
    public function confirm(Request $request, string $id): JsonResponse
    {
        return $this->ok(
            new EntityResource($this->maintenance->confirmDone($request->user(), $id)),
            'Work confirmed. Thank you.',
        );
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,assigned,in-progress,completed,tenant-confirmed,verified,closed'],
            'assignedToId' => ['sometimes', 'nullable', 'string', 'exists:users,id'],
            'vendorId' => ['sometimes', 'nullable', 'string', 'exists:vendors,id'],
            'materialsCost' => ['sometimes', 'integer', 'min:0'],
            'labourCost' => ['sometimes', 'integer', 'min:0'],
            'actualMaterials' => ['sometimes', 'nullable', 'string'],
            'materialsMatch' => ['sometimes', 'boolean'],
            'afterPhotos' => ['sometimes', 'array'],
        ]);

        $status = $data['status'];
        unset($data['status']);

        $record = $this->maintenance->transition($request->user(), $id, $status, $data);

        return $this->ok(new EntityResource($record), "Work order moved to {$status}.");
    }

    public function addRound(Request $request, string $id): JsonResponse
    {
        $round = $request->validate([
            'stage' => ['required', 'in:triage,progress,final'],
            'date' => ['required', 'date'],
            'outcome' => ['required', 'in:ok,issues'],
            'findings' => ['required', 'string'],
            'photos' => ['sometimes', 'array'],
        ]);

        return $this->created(new EntityResource($this->maintenance->addRound($request->user(), $id, $round)), 'Inspection round recorded.');
    }
}
