<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PropertyScope;
use App\Services\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    use ApiResponses;

    private const CAN_SEND = ['superadmin', 'admin', 'owner', 'manager', 'finance'];

    public function __construct(private ReminderService $reminders, private PropertyScope $scope) {}

    /** POST /reminders/overdue — nudge tenants with overdue balances. */
    public function overdue(Request $request): JsonResponse
    {
        [$visible, $only] = $this->scopeFor($request);

        $result = $this->reminders->sendOverdue($visible, $only);

        $msg = $result['invoices'] === 0
            ? 'No overdue invoices — nothing to send.'
            : "Reminders sent for {$result['invoices']} overdue invoice(s).";

        return $this->ok($result, $msg);
    }

    /** POST /reminders/expiring-leases — nudge tenants whose lease is up for renewal soon. */
    public function expiringLeases(Request $request): JsonResponse
    {
        [$visible, $only] = $this->scopeFor($request);

        $days = (int) $request->input('days', 60);

        $result = $this->reminders->sendExpiringLeases($visible, $only, $days);

        $msg = $result['leases'] === 0
            ? 'No leases expiring soon — nothing to send.'
            : "Renewal reminders sent for {$result['leases']} expiring lease(s).";

        return $this->ok($result, $msg);
    }

    /** Shared auth + property-scope check for the on-demand reminder endpoints. */
    private function scopeFor(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($user->role, self::CAN_SEND, true), 403, 'You cannot send reminders.');

        $data = $request->validate([
            'propertyId' => ['sometimes', 'nullable', 'string', 'exists:properties,id'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $visible = $this->scope->visibleIds($user);
        $only = $data['propertyId'] ?? null;

        if ($only !== null && $visible !== null && ! in_array($only, $visible, true)) {
            abort(403, 'That property is outside your access.');
        }

        return [$visible, $only];
    }
}
