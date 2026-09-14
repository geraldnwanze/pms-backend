<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\MetricsService;
use App\Services\PropertyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponses;

    public function __construct(private MetricsService $metrics, private PropertyScope $scope) {}

    private function scopeIds(Request $request): ?array
    {
        return $this->scope->visibleIds($request->user());
    }

    /** GET /api/v1/reports/collections?months= */
    public function collections(Request $request): JsonResponse
    {
        $months = (int) $request->query('months', 12);

        return $this->ok([
            'series' => $this->metrics->rentTrend($this->scopeIds($request), $months),
            'summary' => collect($this->metrics->portfolioKpis($this->scopeIds($request)))
                ->only(['expectedBilled', 'collected', 'outstanding', 'overdue', 'collectionRate']),
        ], 'Collections report.');
    }

    /** GET /api/v1/reports/occupancy */
    public function occupancy(Request $request): JsonResponse
    {
        return $this->ok([
            'byProperty' => $this->metrics->occupancyByProperty($this->scopeIds($request)),
            'portfolio' => collect($this->metrics->portfolioKpis($this->scopeIds($request)))
                ->only(['totalUnits', 'occupied', 'vacant', 'reserved', 'occupancyRate']),
        ], 'Occupancy report.');
    }

    /** GET /api/v1/reports/expenses?from=&to= */
    public function expenses(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        return $this->ok([
            'byCategory' => $this->metrics->expensesByCategory($this->scopeIds($request), $data['from'] ?? null, $data['to'] ?? null),
        ], 'Expenses report.');
    }

    /** GET /api/v1/reports/owner-statement?ownerId=&from=&to= */
    public function ownerStatement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ownerId' => ['required', 'string', 'exists:users,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->id === $data['ownerId'],
            403,
            'You can only pull your own owner statement.',
        );

        return $this->ok(
            $this->metrics->ownerStatement($data['ownerId'], $data['from'] ?? null, $data['to'] ?? null),
            'Owner statement.',
        );
    }
}
