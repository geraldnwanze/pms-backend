<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\User;
use App\Services\MetricsService;
use App\Services\PropertyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin/owner rollups: the Owners and Staff screens.
 */
class DirectoryController extends Controller
{
    use ApiResponses;

    public function __construct(private MetricsService $metrics, private PropertyScope $scope) {}

    /** GET /api/v1/owners — every owner with their portfolio totals. */
    public function owners(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->role === 'owner', 403, 'Owners directory is admin/owner only.');

        $ownerIds = PropertyMember::query()->where('role', 'owner')->where('status', 'active')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('userId', $user->id))
            ->pluck('userId')->filter()->unique();

        $rows = User::query()->whereIn('id', $ownerIds)->get()->map(function (User $owner) {
            $propertyIds = Property::query()
                ->whereJsonContains('owners', [['ownerId' => $owner->id]])
                ->pluck('id')->all();

            $k = $this->metrics->portfolioKpis($propertyIds ?: ['__none__']);

            return [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'avatarColor' => $owner->avatarColor,
                'status' => $owner->status,
                'properties' => count($propertyIds),
                'units' => $k['totalUnits'],
                'occupancyRate' => $k['occupancyRate'],
                'annualRentRoll' => $k['annualRentRoll'],
                'collected' => $k['collected'],
                'outstanding' => $k['outstanding'],
                'netIncome' => $k['netIncome'],
                'teamSize' => PropertyMember::query()->whereIn('propertyId', $propertyIds ?: ['__none__'])
                    ->where('status', 'active')->where('role', '!=', 'owner')->distinct('userId')->count('userId'),
            ];
        })->values();

        return $this->ok($rows, 'Owners directory.');
    }

    /** GET /api/v1/staff — active non-owner members across visible properties. */
    public function staff(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || in_array($user->role, ['owner', 'manager'], true), 403, 'Staff directory is admin/owner/manager only.');

        $propertyIds = $this->scope->visibleIds($user);

        $members = PropertyMember::query()
            ->where('status', 'active')
            ->whereIn('role', ['manager', 'agent', 'finance', 'maintenance'])
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('propertyId', $propertyIds ?: ['__none__']))
            ->get();

        $propertyNames = Property::query()->pluck('name', 'id');
        $users = User::query()->whereIn('id', $members->pluck('userId')->filter()->unique())->get()->keyBy('id');

        $rows = $members->groupBy('userId')->map(function ($group) use ($users, $propertyNames) {
            $u = $users->get($group->first()->userId);

            return [
                'id' => $group->first()->userId,
                'name' => $u?->name,
                'email' => $u?->email ?? $group->first()->email,
                'phone' => $u?->phone,
                'avatarColor' => $u?->avatarColor,
                'status' => $u?->status,
                'assignments' => $group->map(fn (PropertyMember $m) => [
                    'propertyId' => $m->propertyId,
                    'propertyName' => $propertyNames[$m->propertyId] ?? null,
                    'role' => $m->role,
                    'since' => $m->respondedAt,
                ])->values(),
            ];
        })->values();

        return $this->ok($rows, 'Staff directory.');
    }
}
