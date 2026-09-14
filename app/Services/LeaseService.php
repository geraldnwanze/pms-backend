<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeaseService extends CrudService
{
    protected string $model = Lease::class;

    protected array $searchable = ['code', 'notes'];

    protected array $filterable = ['propertyId', 'unitId', 'tenantId', 'status', 'billingCycle'];

    protected array $sortable = ['id', 'code', 'startDate', 'endDate', 'rent', 'status'];

    protected string $defaultSort = '-startDate';

    protected string $idPrefix = 'lease';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'LSE';

    protected array $createDefaults = ['status' => 'active', 'noticePeriodDays' => 90, 'signed' => false];

    public function __construct(
        PropertyScope $scope,
        private AuditService $audit,
        private NotificationService $notifications,
    ) {
        parent::__construct($scope);
    }

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }

    protected function afterCreate(User $user, Model $record, array $data): void
    {
        $this->audit->record($user, 'create', 'Lease', $record->id, "Created lease {$record->code}");
        $this->notifications->push([
            'kind' => 'lease',
            'title' => 'New lease created',
            'body' => "Lease {$record->code} is now on file.",
            'forUserId' => $record->tenantId,
            'link' => '/portal/lease',
        ]);
    }

    public function renew(User $user, string $id, string $newEndDate): Lease
    {
        /** @var Lease $lease */
        $lease = $this->locate($user, $id);
        $this->authorize($user, 'update', $lease);

        $lease->update(['status' => 'renewed', 'endDate' => $newEndDate]);
        $this->audit->record($user, 'edit', 'Lease', $lease->id, "Renewed lease {$lease->code} to {$newEndDate}");
        $this->notifications->push([
            'kind' => 'lease',
            'title' => 'Lease renewed',
            'body' => "Lease {$lease->code} has been renewed through {$newEndDate}.",
            'forUserId' => $lease->tenantId,
            'link' => '/portal/lease',
        ]);

        return $lease->refresh();
    }

    public function terminate(User $user, string $id): Lease
    {
        /** @var Lease $lease */
        $lease = $this->locate($user, $id);
        $this->authorize($user, 'update', $lease);

        $lease->update(['status' => 'terminated']);
        Unit::query()->where('id', $lease->unitId)->update(['status' => 'available']);
        $this->audit->record($user, 'edit', 'Lease', $lease->id, "Terminated lease {$lease->code}");
        $this->notifications->push([
            'kind' => 'lease',
            'title' => 'Lease terminated',
            'body' => "Lease {$lease->code} has been terminated.",
            'forUserId' => $lease->tenantId,
            'link' => '/portal/lease',
        ]);
        $this->notifications->push([
            'kind' => 'lease',
            'title' => 'Unit now available',
            'body' => "Lease {$lease->code} ended — its unit is back on the market.",
            'forRole' => 'agent',
            'link' => "/properties/{$lease->propertyId}",
        ]);

        return $lease->refresh();
    }
}
