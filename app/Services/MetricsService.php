<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Lease;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side portfolio aggregation. Ports `computeKpis()` and the dashboard
 * derivations from the frontend's `src/store/selectors.ts` so a thin client
 * can render the admin dashboard, reports and analytics without pulling the
 * whole dataset.
 *
 * Every method takes `?array $propertyIds` — null means "no restriction"
 * (admin); callers pass PropertyScope::visibleIds($user).
 */
class MetricsService
{
    private const ACTIVE_LEASE = ['active', 'expiring', 'renewed'];

    /** @return array<string,int|float> */
    public function portfolioKpis(?array $propertyIds): array
    {
        $props = $this->scoped(Property::query(), $propertyIds, 'id')->count();
        $buildings = $this->scoped(Building::query(), $propertyIds)->count();

        $units = $this->scoped(Unit::query(), $propertyIds)->get(['status', 'rent']);
        $invoices = $this->scoped(Invoice::query(), $propertyIds)->where('status', '!=', 'cancelled')
            ->get(['total', 'amountPaid', 'status']);
        $expenses = $this->scoped(Expense::query(), $propertyIds)->whereIn('status', ['approved', 'paid'])->sum('amount');
        $leases = $this->scoped(Lease::query(), $propertyIds)->get(['status', 'tenantId']);

        $by = fn (string $status) => $units->where('status', $status)->count();
        $occupied = $by('occupied');
        $vacant = $by('available');
        $reserved = $by('reserved');

        $monthlyExpectedRent = $units->where('status', 'occupied')->sum('rent');
        $expectedBilled = $invoices->sum('total');
        $collected = $invoices->sum('amountPaid');
        $outstanding = $invoices->sum(fn ($i) => max(0, $i->total - $i->amountPaid));
        $overdue = $invoices->where('status', 'overdue')->sum(fn ($i) => max(0, $i->total - $i->amountPaid));

        $activeLeases = $leases->whereIn('status', self::ACTIVE_LEASE)->count();
        $activeTenants = $leases->whereIn('status', self::ACTIVE_LEASE)->pluck('tenantId')->unique()->count();

        $openMaintenance = $this->scoped(MaintenanceRequest::query(), $propertyIds)
            ->whereNotIn('status', ['verified', 'closed'])->count();
        $pendingVerifications = $this->scoped(Payment::query(), $propertyIds)
            ->where('status', 'pending-verification')->count();

        $rentable = $occupied + $vacant + $reserved;

        return [
            'totalProperties' => $props,
            'totalBuildings' => $buildings,
            'totalUnits' => $units->count(),
            'occupied' => $occupied,
            'vacant' => $vacant,
            'reserved' => $reserved,
            'maintenance' => $by('maintenance'),
            'underRenovation' => $by('renovation'),
            'totalTenants' => $activeTenants,
            'activeLeases' => $activeLeases,
            'expiringLeases' => $leases->where('status', 'expiring')->count(),
            'monthlyExpectedRent' => (int) $monthlyExpectedRent,
            'expectedBilled' => (int) $expectedBilled,
            'collected' => (int) $collected,
            'outstanding' => (int) $outstanding,
            'overdue' => (int) $overdue,
            'totalExpenses' => (int) $expenses,
            'netIncome' => (int) ($collected - $expenses),
            'openMaintenance' => $openMaintenance,
            'pendingVerifications' => $pendingVerifications,
            'occupancyRate' => $rentable > 0 ? round($occupied / $rentable * 100, 1) : 0.0,
            'collectionRate' => $expectedBilled > 0 ? round($collected / $expectedBilled * 100, 1) : 0.0,
        ];
    }

    /** Monthly billed vs collected. @return array<int,array<string,mixed>> */
    public function rentTrend(?array $propertyIds, int $months = 8): array
    {
        $rows = $this->scoped(Invoice::query(), $propertyIds)->where('status', '!=', 'cancelled')
            ->get(['issueDate', 'total', 'amountPaid']);

        $byMonth = [];
        foreach ($rows as $inv) {
            $m = substr((string) $inv->issueDate, 0, 7);
            $byMonth[$m] ??= ['month' => $m, 'billed' => 0, 'collected' => 0];
            $byMonth[$m]['billed'] += (int) $inv->total;
            $byMonth[$m]['collected'] += (int) $inv->amountPaid;
        }

        ksort($byMonth);

        return array_slice(array_values($byMonth), -$months);
    }

    /** @return array<string,int> */
    public function unitStatusBreakdown(?array $propertyIds): array
    {
        $counts = $this->scoped(Unit::query(), $propertyIds)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return collect(['occupied', 'available', 'reserved', 'maintenance', 'renovation', 'unavailable'])
            ->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function expiringLeases(?array $propertyIds, int $limit = 6): array
    {
        return $this->scoped(Lease::query(), $propertyIds)
            ->where('status', 'expiring')->orderBy('endDate')->limit($limit)
            ->get(['id', 'code', 'propertyId', 'unitId', 'tenantId', 'endDate', 'rent'])->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    public function overdueInvoices(?array $propertyIds, int $limit = 6): array
    {
        return $this->scoped(Invoice::query(), $propertyIds)
            ->where('status', 'overdue')->orderBy('dueDate')->limit($limit)
            ->get(['id', 'code', 'propertyId', 'tenantId', 'dueDate', 'total', 'amountPaid'])
            ->map(fn ($i) => $i->toArray() + ['outstanding' => max(0, $i->total - $i->amountPaid)])
            ->toArray();
    }

    /** Per-property occupancy + collection. @return array<int,array<string,mixed>> */
    public function occupancyByProperty(?array $propertyIds): array
    {
        return $this->scoped(Property::query(), $propertyIds, 'id')->get(['id', 'name', 'code', 'city', 'state'])
            ->map(function (Property $p) {
                $k = $this->portfolioKpis([$p->id]);

                return [
                    'propertyId' => $p->id,
                    'name' => $p->name,
                    'code' => $p->code,
                    'city' => $p->city,
                    'state' => $p->state,
                    'units' => $k['totalUnits'],
                    'occupied' => $k['occupied'],
                    'vacant' => $k['vacant'],
                    'occupancyRate' => $k['occupancyRate'],
                    'monthlyExpectedRent' => $k['monthlyExpectedRent'],
                    'collected' => $k['collected'],
                    'outstanding' => $k['outstanding'],
                    'collectionRate' => $k['collectionRate'],
                    'netIncome' => $k['netIncome'],
                ];
            })->toArray();
    }

    /** Expenses grouped by category, optionally within a date window. */
    public function expensesByCategory(?array $propertyIds, ?string $from, ?string $to): array
    {
        return $this->scoped(Expense::query(), $propertyIds)
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->selectRaw('category, count(*) as count, sum(amount) as total')
            ->groupBy('category')->orderByDesc('total')
            ->get()->map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->count, 'total' => (int) $r->total])
            ->toArray();
    }

    /** Monthly billed / collected / expenses / net for the analytics screen. */
    public function monthlySeries(?array $propertyIds, int $months = 12): array
    {
        $trend = collect($this->rentTrend($propertyIds, $months))->keyBy('month');

        $expenseRows = $this->scoped(Expense::query(), $propertyIds)->whereIn('status', ['approved', 'paid'])
            ->get(['date', 'amount']);
        $expByMonth = [];
        foreach ($expenseRows as $e) {
            $m = substr((string) $e->date, 0, 7);
            $expByMonth[$m] = ($expByMonth[$m] ?? 0) + (int) $e->amount;
        }

        return $trend->map(fn ($t) => [
            'month' => $t['month'],
            'billed' => $t['billed'],
            'collected' => $t['collected'],
            'expenses' => $expByMonth[$t['month']] ?? 0,
            'netIncome' => $t['collected'] - ($expByMonth[$t['month']] ?? 0),
        ])->values()->toArray();
    }

    /** Owner statement: income/expense for one owner over a period. */
    public function ownerStatement(string $ownerId, ?string $from, ?string $to): array
    {
        $propertyIds = Property::query()
            ->whereJsonContains('owners', [['ownerId' => $ownerId]])
            ->pluck('id')->all();

        $window = fn (Builder $q, string $col) => $q
            ->when($from, fn ($x) => $x->where($col, '>=', $from))
            ->when($to, fn ($x) => $x->where($col, '<=', $to));

        $collected = $window(Payment::query()->whereIn('propertyId', $propertyIds ?: ['__none__'])->where('status', 'approved'), 'paymentDate')->sum('amount');
        $expenses = $window(Expense::query()->whereIn('propertyId', $propertyIds ?: ['__none__'])->whereIn('status', ['approved', 'paid']), 'date')->sum('amount');

        return [
            'ownerId' => $ownerId,
            'from' => $from,
            'to' => $to,
            'properties' => count($propertyIds),
            'grossCollections' => (int) $collected,
            'operatingExpenses' => (int) $expenses,
            'netToOwner' => (int) ($collected - $expenses),
            'byProperty' => $this->occupancyByProperty($propertyIds ?: ['__none__']),
        ];
    }

    // ---- Persona focus blocks ------------------------------------------

    /**
     * Payments sitting in the verification queue — the finance persona's main
     * job, and a secondary panel for operations.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendingVerificationPayments(?array $propertyIds, int $limit = 20): array
    {
        return $this->scoped(Payment::query(), $propertyIds)
            ->where('status', 'pending-verification')
            ->orderByDesc('submittedAt')->limit($limit)
            ->get(['id', 'code', 'propertyId', 'tenantId', 'invoiceId', 'amount', 'method', 'paymentDate', 'submittedAt'])
            ->toArray();
    }

    /**
     * Expenses awaiting approval (finance persona).
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendingExpenses(?array $propertyIds, int $limit = 20): array
    {
        return $this->scoped(Expense::query(), $propertyIds)
            ->where('status', 'pending')
            ->orderByDesc('date')->limit($limit)
            ->get(['id', 'code', 'propertyId', 'vendorId', 'category', 'description', 'amount', 'date'])
            ->toArray();
    }

    /**
     * Work-order board. With $assigneeId the queue is limited to one technician
     * (the maintenance persona); without it, it spans the scope (operations).
     *
     * @return array<string,mixed>
     */
    public function maintenanceQueue(?array $propertyIds, ?string $assigneeId, int $limit = 15): array
    {
        $q = fn () => $this->scoped(MaintenanceRequest::query(), $propertyIds)
            ->when($assigneeId !== null, fn ($x) => $x->where('assignedToId', $assigneeId));

        $byStatus = $q()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $byUrgency = $q()->selectRaw('urgency, count(*) as n')->groupBy('urgency')->pluck('n', 'urgency');

        $items = $q()
            ->whereNotIn('status', ['verified', 'closed'])
            ->orderByRaw("case urgency when 'emergency' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderByDesc('createdAt')->limit($limit)
            ->get(['id', 'code', 'title', 'category', 'urgency', 'status', 'propertyId', 'unitId', 'assignedToId', 'createdAt'])
            ->toArray();

        return [
            'byStatus' => collect(['open', 'assigned', 'in-progress', 'completed', 'verified', 'closed'])
                ->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)])->all(),
            'byUrgency' => collect(['emergency', 'high', 'medium', 'low'])
                ->mapWithKeys(fn ($s) => [$s => (int) ($byUrgency[$s] ?? 0)])->all(),
            'openCount' => (int) $q()->whereNotIn('status', ['verified', 'closed'])->count(),
            'emergencyCount' => (int) ($byUrgency['emergency'] ?? 0),
            'items' => $items,
        ];
    }

    /**
     * Leasing pipeline for the agent persona: listings, lead funnel and the
     * units that still need filling.
     *
     * @return array<string,mixed>
     */
    public function leasingBoard(?array $propertyIds, ?string $agentId, int $limit = 10): array
    {
        $leads = fn () => Lead::query()
            ->when($propertyIds !== null, fn ($x) => $x->where(fn ($w) => $w
                ->whereIn('propertyId', $propertyIds ?: ['__none__'])->orWhereNull('propertyId')))
            ->when($agentId !== null, fn ($x) => $x->where('agentId', $agentId));

        $funnel = $leads()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $active = ['new', 'contacted', 'viewing-scheduled', 'negotiating'];

        return [
            'liveListings' => (int) $this->scoped(Listing::query(), $propertyIds)->where('status', 'published')->count(),
            'activeLeads' => (int) $leads()->whereIn('status', $active)->count(),
            'viewingsScheduled' => (int) $leads()->whereNotNull('viewingDate')
                ->whereIn('status', ['viewing-scheduled', 'negotiating'])->count(),
            'convertedLeads' => (int) ($funnel['converted'] ?? 0),
            'vacantUnits' => (int) $this->scoped(Unit::query(), $propertyIds)->where('status', 'available')->count(),
            'leadFunnel' => collect(['new', 'contacted', 'viewing-scheduled', 'negotiating', 'converted', 'lost'])
                ->mapWithKeys(fn ($s) => [$s => (int) ($funnel[$s] ?? 0)])->all(),
            'recentLeads' => $leads()->orderByDesc('createdAt')->limit($limit)
                ->get(['id', 'name', 'status', 'propertyId', 'listingId', 'agentId', 'createdAt', 'viewingDate'])
                ->toArray(),
            'availableUnits' => $this->scoped(Unit::query(), $propertyIds)
                ->whereIn('status', ['available', 'reserved'])
                ->orderByDesc('rent')->limit($limit)
                ->get(['id', 'code', 'type', 'propertyId', 'buildingId', 'rent', 'status'])
                ->toArray(),
        ];
    }

    // ---- helper ---------------------------------------------------------

    private function scoped(Builder $query, ?array $propertyIds, string $column = 'propertyId'): Builder
    {
        if ($propertyIds !== null) {
            $query->whereIn($column, $propertyIds ?: ['__none__']);
        }

        return $query;
    }
}
