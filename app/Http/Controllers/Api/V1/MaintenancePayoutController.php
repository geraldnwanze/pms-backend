<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Services\MaintenancePayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenancePayoutController extends Controller
{
    use ApiResponses;

    public function __construct(private MaintenancePayoutService $payouts) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(
            EntityResource::collection($this->payouts->visibleTo($request->user())),
            'Maintenance payouts.',
        );
    }

    /** Technician requests payment for a confirmed work order. */
    public function request(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['sometimes', 'nullable', 'integer', 'gt:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $payout = $this->payouts->request($request->user(), $id, $data['amount'] ?? null, $data['note'] ?? null);

        return $this->created(new EntityResource($payout), 'Payment requested.');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->ok(new EntityResource($this->payouts->approve($request->user(), $id)), 'Payout approved.');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3']]);

        return $this->ok(new EntityResource($this->payouts->reject($request->user(), $id, $data['reason'])), 'Payout rejected.');
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'method' => ['sometimes', 'nullable', 'in:bank-transfer,cash,paystack-transfer'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $payout = $this->payouts->markPaid($request->user(), $id, $data['method'] ?? null, $data['reference'] ?? null);

        return $this->ok(new EntityResource($payout), 'Payout marked as paid.');
    }
}
