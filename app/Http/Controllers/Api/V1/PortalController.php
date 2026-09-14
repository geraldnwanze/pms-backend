<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/portal — the resident (tenant) home dashboard, computed on the
 * server and scoped to the caller's own tenant record(s).
 */
class PortalController extends Controller
{
    use ApiResponses;

    private const ACTIVE_LEASE = ['active', 'expiring', 'renewed'];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantIds = $user->tenantRecordIds();

        if ($tenantIds === []) {
            return $this->ok(['linked' => false], 'No tenancy is linked to this account yet.');
        }

        $tenant = Tenant::query()->whereIn('id', $tenantIds)
            ->first(['id', 'name', 'email', 'phone', 'avatarColor']);

        $leases = Lease::query()->whereIn('tenantId', $tenantIds)->orderByDesc('startDate')->get();
        $lease = $leases->first(fn ($l) => in_array($l->status, self::ACTIVE_LEASE, true)) ?? $leases->first();

        $unit = $lease ? Unit::query()->find($lease->unitId) : null;
        $property = $lease ? Property::query()->find($lease->propertyId) : null;
        $building = $unit ? Building::query()->find($unit->buildingId) : null;

        $invoices = Invoice::query()->whereIn('tenantId', $tenantIds)
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'code', 'issueDate', 'dueDate', 'total', 'amountPaid', 'status']);
        $outstanding = $invoices->filter(fn ($i) => ($i->total - $i->amountPaid) > 0);
        $balance = (int) $invoices->sum(fn ($i) => max(0, $i->total - $i->amountPaid));
        $nextDue = $outstanding->sortBy('dueDate')->first();

        $recentPayments = Payment::query()->whereIn('tenantId', $tenantIds)
            ->orderByDesc('submittedAt')->limit(6)
            ->get(['id', 'code', 'amount', 'method', 'type', 'paymentDate', 'reference', 'status']);
        $pendingPaymentsCount = Payment::query()->whereIn('tenantId', $tenantIds)
            ->where('status', 'pending-verification')->count();

        $requests = MaintenanceRequest::query()->whereIn('tenantId', $tenantIds)
            ->orderByDesc('createdAt')
            ->get(['id', 'code', 'title', 'category', 'urgency', 'status', 'createdAt']);
        $openRequestsCount = $requests->whereNotIn('status', ['verified', 'closed'])->count();

        $notices = Notice::query()
            ->whereIn('audience', ['all', 'tenants'])
            ->where(fn ($q) => $q->whereNull('propertyId')
                ->when($property, fn ($x) => $x->orWhere('propertyId', $property->id)))
            ->orderByDesc('createdAt')->limit(6)
            ->get(['id', 'title', 'body', 'audience', 'createdAt', 'propertyId']);

        return $this->ok([
            'linked' => true,
            'tenant' => $tenant,
            'lease' => $lease,
            'unit' => $unit,
            'property' => $property ? [
                'id' => $property->id, 'name' => $property->name, 'address' => $property->address,
                'city' => $property->city, 'state' => $property->state, 'amenities' => $property->amenities ?? [],
            ] : null,
            'building' => $building ? ['id' => $building->id, 'name' => $building->name] : null,
            'balance' => $balance,
            'nextDue' => $nextDue ? [
                'id' => $nextDue->id, 'code' => $nextDue->code, 'dueDate' => $nextDue->dueDate,
                'outstanding' => (int) max(0, $nextDue->total - $nextDue->amountPaid),
            ] : null,
            'openInvoicesCount' => $outstanding->count(),
            'pendingPaymentsCount' => $pendingPaymentsCount,
            'openRequestsCount' => $openRequestsCount,
            'recentPayments' => $recentPayments,
            'recentRequests' => $requests->take(5)->values(),
            'notices' => $notices,
        ], 'Resident dashboard for '.($tenant->name ?? $user->name).'.');
    }
}
