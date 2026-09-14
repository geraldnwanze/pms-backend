<?php

namespace App\Services;

use App\Models\AuditLog;
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
    ): AuditLog {
        $userId = $actor instanceof User ? $actor->id : ($actor ?? 'system');
        $request = request();

        return AuditLog::query()->create([
            'id' => 'aud_'.Str::lower(Str::random(12)),
            'action' => $action,
            'entity' => $entity,
            'entityId' => $entityId,
            'userId' => $userId,
            'summary' => $summary,
            'timestamp' => now()->toIso8601String(),
            'ip' => $request instanceof Request ? $request->ip() : null,
            'device' => $request instanceof Request ? Str::limit($request->userAgent() ?? 'API', 120, '') : 'API',
        ]);
    }
}
