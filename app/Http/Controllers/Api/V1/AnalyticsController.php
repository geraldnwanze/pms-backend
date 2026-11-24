<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\MaintenanceRequest;
use App\Services\MetricsService;
use App\Services\PropertyScope;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/analytics — trend series for the analytics screen.
 */
class AnalyticsController extends Controller
{
    use ApiResponses;

    public function __invoke(Request $request, MetricsService $metrics, PropertyScope $scope, Modules $modules): JsonResponse
    {
        $propertyIds = $scope->visibleIds($request->user());
        $months = (int) $request->query('months', 12);

        $maintByMonth = ! $modules->enabled('maintenance') ? collect() : MaintenanceRequest::query()
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('propertyId', $propertyIds ?: ['__none__']))
            ->get(['createdAt', 'status'])
            ->groupBy(fn ($m) => substr((string) $m->createdAt, 0, 7))
            ->map(fn ($g) => [
                'opened' => $g->count(),
                'closed' => $g->whereIn('status', ['verified', 'closed'])->count(),
            ]);

        $leadFunnel = ! $modules->enabled('leasing') ? collect() : Lead::query()
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('propertyId', $propertyIds ?: ['__none__']))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return $this->ok([
            'financials' => $metrics->monthlySeries($propertyIds, $months),
            'maintenanceByMonth' => $maintByMonth->map(fn ($v, $k) => ['month' => $k] + $v)->values(),
            'leadFunnel' => collect(['new', 'contacted', 'viewing-scheduled', 'negotiating', 'converted', 'lost'])
                ->mapWithKeys(fn ($s) => [$s => (int) ($leadFunnel[$s] ?? 0)]),
            'expensesByCategory' => $modules->enabled('expenses') ? $metrics->expensesByCategory($propertyIds, null, null) : [],
        ], 'Analytics.');
    }
}
