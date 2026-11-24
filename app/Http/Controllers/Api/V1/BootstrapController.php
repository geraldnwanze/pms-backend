<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\PropertyMember;
use App\Models\User;
use App\Services\CrudService;
use App\Services\MaintenancePayoutService;
use App\Services\MembershipService;
use App\Services\PaystackService;
use App\Services\PropertyScope;
use App\Services\SettingsService;
use App\Support\EntityRegistry;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One request that returns every collection the authenticated user may see,
 * keyed like the frontend's Redux `data` slice, plus the user's own
 * memberships and pending invitations. The SPA calls this after login and
 * after each mutation to converge on server state.
 *
 * Everything is filtered by RBAC scope (App\Services\PropertyScope): a user
 * with no accepted membership gets empty collections and just their invites.
 */
class BootstrapController extends Controller
{
    use ApiResponses;

    public function __invoke(Request $request, PropertyScope $scope, MembershipService $memberships, SettingsService $settings, PaystackService $paystack, MaintenancePayoutService $payouts, Modules $modules): JsonResponse
    {
        $user = $request->user();
        $data = [];

        foreach (EntityRegistry::all() as $key => $entity) {
            /** @var CrudService $service */
            $service = app($entity['service']);

            // A module that is turned off sends nothing: its screens are hidden and its data is not shipped.
            $module = Modules::forResource($key);
            $data[$key] = (! $module || $modules->enabled($module)) && $service->mayAccessCollection($user, 'view')
                ? $service->listAll($user)->toArray()
                : [];
        }

        $data['users'] = $this->directory($user, $scope);
        $data['maintenancePayouts'] = $payouts->visibleTo($user)->toArray();
        $data['memberships'] = $memberships->allFor($user);
        $data['invitations'] = $memberships->pendingFor($user);
        $data['settings'] = $settings->get('organization');

        $integrations = $settings->get('integrations');
        // Surface whether Paystack keys are actually present (not just toggled on).
        $integrations['paystack'] = array_merge($integrations['paystack'] ?? [], [
            'configured' => $paystack->configured(),
        ]);
        $data['integrations'] = $integrations;
        $data['modules'] = $modules->states();
        $data['rbac'] = [
            'roles' => config('rbac.roles'),
            'permissions' => config('rbac.permissions'),
            'resources' => config('rbac.resources'),
        ];

        return $this->ok($data, 'Bootstrap dataset for '.$user->name.'.');
    }

    /**
     * Name directory: only the people relevant to this user — themselves, the
     * teams of properties they can see, and anyone who invited them.
     */
    private function directory(User $user, PropertyScope $scope): array
    {
        if ($user->isAdmin()) {
            return User::query()->get()->toArray();
        }

        $propertyIds = $scope->visibleIds($user);

        $ids = PropertyMember::query()
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('propertyId', $propertyIds))
            ->get()
            ->flatMap(fn (PropertyMember $m) => [$m->userId, $m->invitedById])
            ->merge(
                PropertyMember::query()->where('userId', $user->id)->pluck('invitedById')
            )
            ->push($user->id)
            ->filter()
            ->unique()
            ->values();

        // Non-admins never see super-admin accounts, even if one invited them.
        return User::query()
            ->whereIn('id', $ids)
            ->whereNotIn('role', User::ADMIN_ROLES)
            ->get()
            ->toArray();
    }
}
