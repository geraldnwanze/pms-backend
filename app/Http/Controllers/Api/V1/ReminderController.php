<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\PropertyScope;
use App\Services\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    use ApiResponses;

    private const CAN_SEND = ['admin', 'owner', 'manager', 'finance'];

    public function __construct(private ReminderService $reminders, private PropertyScope $scope) {}

    /** POST /reminders/overdue — nudge tenants with overdue balances. */
    public function overdue(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, self::CAN_SEND, true), 403, 'You cannot send reminders.');

        $data = $request->validate([
            'propertyId' => ['sometimes', 'nullable', 'string', 'exists:properties,id'],
        ]);

        $visible = $this->scope->visibleIds($user);
        $only = $data['propertyId'] ?? null;

        if ($only !== null && $visible !== null && ! in_array($only, $visible, true)) {
            abort(403, 'That property is outside your access.');
        }

        $result = $this->reminders->sendOverdue($visible, $only);

        $msg = $result['invoices'] === 0
            ? 'No overdue invoices — nothing to send.'
            : "Reminders sent for {$result['invoices']} overdue invoice(s).";

        return $this->ok($result, $msg);
    }
}
