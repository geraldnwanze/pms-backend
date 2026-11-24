<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenancePayout;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\PropertyMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes rows to the audit trail. Injected into the workflow services so every
 * state change (payment approved, lease terminated, …) is recorded.
 */
class AuditService
{
    public function record(
        User|string|null $actor,
        string $action,
        string $entity,
        ?string $entityId,
        string $summary,
        ?string $source = null,
    ): AuditLog {
        $userId = $actor instanceof User ? $actor->id : ($actor ?? 'system');
        $request = request();

        return AuditLog::query()->create([
            'propertyId' => $this->propertyFor($entity, $entityId),
            'id' => 'aud_'.Str::lower(Str::random(12)),
            'action' => $action,
            'entity' => $entity,
            'entityId' => $entityId,
            'userId' => $userId,
            'summary' => $summary,
            'timestamp' => now()->toIso8601String(),
            // `$source` names where a change came from when it did not come from a request (the command line), instead of a made-up address.
            'ip' => $source !== null ? null : ($request instanceof Request ? $request->ip() : null),
            'device' => $source ?? ($request instanceof Request ? Str::limit($request->userAgent() ?? 'API', 120, '') : 'API'),
        ]);
    }

    /** entity name → model class, for the records whose property we can look up. */
    private const MODELS = [
        'Invoice' => Invoice::class, 'Lease' => Lease::class, 'Maintenance' => MaintenanceRequest::class,
        'MaintenancePayout' => MaintenancePayout::class, 'Expense' => Expense::class, 'Payment' => Payment::class,
        'PropertyMember' => PropertyMember::class,
    ];

    /**
     * The property an audited record belongs to, so the trail can be scoped to the people who run it.
     * Null for account-level events (sign-ins, settings, users) — those are for administrators.
     */
    private function propertyFor(string $entity, ?string $entityId): ?string
    {
        if ($entityId === null) {
            return null;
        }
        if ($entity === 'Property') {
            return $entityId;
        }
        $model = self::MODELS[$entity] ?? null;

        return $model ? $model::query()->whereKey($entityId)->value('propertyId') : null;
    }
}
