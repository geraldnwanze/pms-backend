<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\MetricsService;
use App\Services\PropertyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/v1/dashboard — the overview screen, computed on the server and
 * scoped to the caller (admin = whole system).
 *
 * The payload is tailored to a "persona" derived from the caller's role so
 * finance, operations, leasing and maintenance users get a dashboard about
 * their own work rather than the portfolio rollup. Tenants use /portal.
 */
class DashboardController extends Controller
{
    use ApiResponses;

    /** role → dashboard persona (drives both the layout and the payload shape). */
    private const PERSONA = [
        'admin' => 'portfolio',
        'owner' => 'portfolio',
        'finance' => 'finance',
        'manager' => 'operations',
        'agent' => 'leasing',
        'maintenance' => 'maintenance',
    ];

    public function __invoke(Request $request, MetricsService $metrics, PropertyScope $scope): JsonResponse
    {
        $user = $request->user();

        if ($user->isTenantOnly()) {
            abort(403, 'Tenants use the resident portal.');
        }

        $propertyIds = $scope->visibleIds($user);

        // Optional global property filter (Topbar). Admins (null scope) may pick
        // any property; scoped users may only narrow to one they already see.
        $only = $request->query('propertyId');
        if ($only !== null && $only !== '' && $only !== 'all') {
            if ($propertyIds !== null && ! in_array($only, $propertyIds, true)) {
                abort(403, 'That property is outside your access.');
            }
            $propertyIds = [$only];
        }

        $persona = self::PERSONA[$user->role] ?? 'newcomer';

        $payload = [
            'role' => $user->role,
            'persona' => $persona,
            'kpis' => $metrics->portfolioKpis($propertyIds),
            'rentTrend' => $metrics->rentTrend($propertyIds, 8),
            'recentActivity' => $this->recentActivity($user, $propertyIds),
        ];

        $payload += match ($persona) {
            'portfolio' => [
                'unitStatus' => $metrics->unitStatusBreakdown($propertyIds),
                'occupancyByProperty' => $metrics->occupancyByProperty($propertyIds),
                'expiringLeases' => $metrics->expiringLeases($propertyIds),
                'overdueInvoices' => $metrics->overdueInvoices($propertyIds),
            ],
            'finance' => [
                'overdueInvoices' => $metrics->overdueInvoices($propertyIds, 12),
                'pendingPayments' => $metrics->pendingVerificationPayments($propertyIds),
                'expensesPending' => $metrics->pendingExpenses($propertyIds),
                'expensesByCategory' => $metrics->expensesByCategory($propertyIds, null, null),
            ],
            'operations' => [
                'unitStatus' => $metrics->unitStatusBreakdown($propertyIds),
                'expiringLeases' => $metrics->expiringLeases($propertyIds, 10),
                'pendingPayments' => $metrics->pendingVerificationPayments($propertyIds, 10),
                'maintenanceQueue' => $metrics->maintenanceQueue($propertyIds, null),
            ],
            'leasing' => [
                'leasing' => $metrics->leasingBoard($propertyIds, $user->id),
            ],
            'maintenance' => [
                'maintenanceQueue' => $metrics->maintenanceQueue($propertyIds, $user->id, 20),
            ],
            default => [],
        };

        return $this->ok($payload, 'Dashboard for '.$user->name.'.');
    }

    /**
     * Recent audit trail — admin/finance see everything *on the properties in view*, the rest only their own actions.
     *
     * @param  array<int,string>|null  $propertyIds  null = no restriction (admin, unfiltered)
     */
    private function recentActivity(User $user, ?array $propertyIds): Collection
    {
        return AuditLog::query()
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('propertyId', $propertyIds ?: ['__none__']))
            ->when(! in_array($user->role, ['admin', 'finance'], true), fn ($q) => $q->where('userId', $user->id))
            ->orderByDesc('timestamp')->limit(12)
            ->get(['id', 'action', 'entity', 'entityId', 'userId', 'summary', 'timestamp']);
    }
}
