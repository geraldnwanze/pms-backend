<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\MetricsService;
use App\Services\PropertyScope;
use App\Support\Modules;
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
        'superadmin' => 'portfolio',
        'admin' => 'portfolio',
        'owner' => 'portfolio',
        'finance' => 'finance',
        'manager' => 'operations',
        'agent' => 'leasing',
        'maintenance' => 'maintenance',
    ];

    public function __invoke(Request $request, MetricsService $metrics, PropertyScope $scope, Modules $modules): JsonResponse
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
                'expensesPending' => $modules->enabled('expenses') ? $metrics->pendingExpenses($propertyIds) : [],
                'expensesByCategory' => $modules->enabled('expenses') ? $metrics->expensesByCategory($propertyIds, null, null) : [],
            ],
            'operations' => [
                'unitStatus' => $metrics->unitStatusBreakdown($propertyIds),
                'expiringLeases' => $metrics->expiringLeases($propertyIds, 10),
                'pendingPayments' => $metrics->pendingVerificationPayments($propertyIds, 10),
                'maintenanceQueue' => $modules->enabled('maintenance') ? $metrics->maintenanceQueue($propertyIds, null) : $this->noMaintenanceQueue(),
            ],
            'leasing' => [
                'leasing' => $this->leasingBoard($metrics->leasingBoard($propertyIds, $user->id), $modules),
            ],
            'maintenance' => [
                'maintenanceQueue' => $modules->enabled('maintenance') ? $metrics->maintenanceQueue($propertyIds, $user->id, 20) : $this->noMaintenanceQueue(),
            ],
            default => [],
        };

        return $this->ok($payload, 'Dashboard for '.$user->name.'.');
    }

    /** The queue's own shape with nothing in it, so the screen reads zeros rather than a missing field. */
    private function noMaintenanceQueue(): array
    {
        return [
            'byStatus' => array_fill_keys(['open', 'assigned', 'in-progress', 'completed', 'tenant-confirmed', 'verified', 'closed'], 0),
            'byUrgency' => array_fill_keys(['emergency', 'high', 'medium', 'low'], 0),
            'openCount' => 0,
            'emergencyCount' => 0,
            'items' => [],
        ];
    }

    /** With Leasing & leads off the board keeps the vacant units (which are core) and shows no listings or leads. */
    private function leasingBoard(array $board, Modules $modules): array
    {
        if ($modules->enabled('leasing')) {
            return $board;
        }

        return array_merge($board, [
            'liveListings' => 0, 'activeLeads' => 0, 'viewingsScheduled' => 0, 'convertedLeads' => 0,
            'leadFunnel' => array_map(fn () => 0, $board['leadFunnel']), 'recentLeads' => [],
        ]);
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
            ->when(! ($user->isAdmin() || $user->role === 'finance'), fn ($q) => $q->where('userId', $user->id))
            ->orderByDesc('timestamp')->limit(12)
            ->get(['id', 'action', 'entity', 'entityId', 'userId', 'summary', 'timestamp']);
    }
}
