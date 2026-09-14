<?php

namespace App\Services;

use App\Mail\MaintenanceRequestedMail;
use App\Mail\MaintenanceUpdateMail;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\Recipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Maintenance work orders. Ports `createMaintenance`, `updateMaintenance` and
 * `addMaintenanceRound` from the frontend dataSlice.
 */
class MaintenanceService extends CrudService
{
    protected string $model = MaintenanceRequest::class;

    protected array $searchable = ['title', 'code', 'category', 'description'];

    protected array $filterable = ['propertyId', 'unitId', 'status', 'urgency', 'category', 'assignedToId', 'vendorId'];

    protected array $sortable = ['id', 'code', 'createdAt', 'urgency', 'status'];

    protected string $defaultSort = '-createdAt';

    protected string $idPrefix = 'mnt';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'MNT';

    protected array $nowOnCreate = ['createdAt'];

    protected array $createDefaults = [
        'status' => 'open',
        'materialsCost' => 0,
        'labourCost' => 0,
        'rounds' => [],
        'photos' => [],
        'afterPhotos' => [],
    ];

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

    /**
     * May this user act on a work order (change its status, log a round)?
     *
     * Owners and managers of the property always may — owners don't hold the
     * generic `edit` permission but still oversee maintenance. Everyone else
     * goes through the normal policy: maintenance staff (who have `edit`) pass;
     * tenants, who only `create`, do not.
     */
    private function assertCanOperate(User $user, MaintenanceRequest $m): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (in_array($user->role, ['owner', 'manager'], true) && $this->scope->canSee($user, $m->propertyId)) {
            return;
        }

        $this->authorize($user, 'update', $m);
    }

    /**
     * Only a property owner/manager (of the request's property) or an admin may
     * decide who a work order is assigned to — a technician can't assign work
     * (including to themselves).
     */
    private function assertMayAssign(User $user, ?string $propertyId): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $role = $propertyId ? $user->roleOnProperty($propertyId) : null;

        abort_unless(
            in_array($role, ['owner', 'manager'], true),
            403,
            'Only a property owner, manager or administrator can assign maintenance staff.',
        );
    }

    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        if (! empty($data['assignedToId'])) {
            $this->assertMayAssign($user, $data['propertyId'] ?? null);
        }

        return $data;
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        /** @var MaintenanceRequest $record */
        if (array_key_exists('assignedToId', $data) && $data['assignedToId'] !== $record->assignedToId) {
            $this->assertMayAssign($user, $record->propertyId);
        }

        return $data;
    }

    protected function afterCreate(User $user, Model $record, array $data): void
    {
        $this->audit->record($user, 'create', 'Maintenance', $record->id, "Logged {$record->code}: {$record->title}");
        $this->notifications->push([
            'kind' => 'maintenance',
            'title' => 'New maintenance request',
            'body' => "{$record->code}: {$record->title} ({$record->urgency})",
            'forRole' => 'manager',
            'link' => '/maintenance',
        ]);

        $recipients = Recipients::propertyManagers($record->propertyId);
        if ($recipients->isNotEmpty()) {
            Mail::to($recipients->all())->queue(new MaintenanceRequestedMail([
                'code' => $record->code,
                'title' => $record->title,
                'category' => $record->category,
                'urgency' => $record->urgency,
                'propertyName' => Property::query()->whereKey($record->propertyId)->value('name') ?? '—',
                'unitCode' => $record->unitId ? Unit::query()->whereKey($record->unitId)->value('code') : null,
                'boardUrl' => FrontendUrl::to('/maintenance/'.$record->id),
            ]));
        }
    }

    /**
     * Tenant confirms the technician's work was actually done. This is the gate
     * for `verified` and for the technician requesting payment.
     */
    public function confirmDone(User $user, string $id): MaintenanceRequest
    {
        /** @var MaintenanceRequest $m */
        $m = $this->locate($user, $id);

        abort_unless(
            $user->isAdmin() || ($m->tenantId && in_array($m->tenantId, $user->tenantRecordIds(), true)),
            403,
            'Only the tenant on this request can confirm the work.',
        );
        abort_unless($m->status === 'completed', 422, 'The job must be marked complete before you can confirm it.');

        $m->forceFill([
            'status' => 'tenant-confirmed',
            'tenantConfirmedAt' => now()->toIso8601String(),
            'tenantConfirmedById' => $user->id,
        ])->save();

        $this->audit->record($user, 'edit', 'Maintenance', $m->id, "Tenant confirmed work on {$m->code}");
        $this->notifications->push([
            'kind' => 'maintenance',
            'title' => 'Tenant confirmed the work',
            'body' => "{$m->code}: {$m->title} — the tenant confirmed it's done. Ready to verify.",
            'forRole' => 'manager',
            'link' => "/maintenance/{$m->id}",
        ]);
        if ($m->assignedToId) {
            $this->notifications->push([
                'kind' => 'maintenance',
                'title' => 'Work confirmed — you can request payment',
                'body' => "The tenant confirmed your work on {$m->code}. You can now request payment.",
                'forUserId' => $m->assignedToId,
                'link' => "/maintenance/{$m->id}",
            ]);
        }

        $this->emailUpdate(
            $m,
            Recipients::propertyManagers($m->propertyId)->merge($this->assignee($m)),
            [
                'subject' => "Tenant confirmed {$m->code}",
                'heading' => 'The tenant confirmed the work',
                'intro' => 'The tenant has confirmed this job was done. A manager can now verify it, and the technician can request payment.',
                'ctaLabel' => 'Open the work order',
            ],
        );

        return $m->refresh();
    }

    /** Move a work order to a new status, stamping the matching timestamp. */
    public function transition(User $user, string $id, string $status, array $changes = []): MaintenanceRequest
    {
        /** @var MaintenanceRequest $m */
        $m = $this->locate($user, $id);
        $this->assertCanOperate($user, $m);

        if (array_key_exists('assignedToId', $changes) && $changes['assignedToId'] !== $m->assignedToId) {
            $this->assertMayAssign($user, $m->propertyId);
        }

        if ($status === 'verified' && ! $user->isAdmin()) {
            abort_unless(
                $m->status === 'tenant-confirmed',
                422,
                'The tenant must confirm the work was done before it can be verified.',
            );
        }

        $previous = $m->status;
        $previousAssignee = $m->assignedToId;
        $m->fill($changes);
        $m->status = $status;

        if ($status !== $previous) {
            match ($status) {
                'completed' => $m->completedAt = now()->toIso8601String(),
                'verified' => $m->verifiedById = $user->id,
                'closed' => $m->closedAt = now()->toIso8601String(),
                default => null,
            };
            $this->audit->record($user, 'edit', 'Maintenance', $m->id, "{$m->code} status → {$status}");
        }

        $m->save();

        $this->emailAfterTransition($m, $previous, $previousAssignee);

        return $m->refresh();
    }

    /** Fire the right notification email(s) for a status / assignee change. */
    private function emailAfterTransition(MaintenanceRequest $m, string $previousStatus, ?string $previousAssignee): void
    {
        $assigneeChanged = $m->assignedToId !== $previousAssignee;
        $statusChanged = $m->status !== $previousStatus;

        if ($assigneeChanged && $m->assignedToId) {
            $this->emailUpdate($m, $this->assignee($m), [
                'subject' => "You've been assigned {$m->code}",
                'heading' => "You've been assigned a work order",
                'intro' => 'A new job has been assigned to you.',
                'ctaLabel' => 'Open the work order',
            ]);
        }

        if (! $statusChanged) {
            return;
        }

        match ($m->status) {
            'assigned' => $assigneeChanged ? null : $this->emailUpdate($m, $this->assignee($m), [
                'subject' => "You've been assigned {$m->code}",
                'heading' => "You've been assigned a work order",
                'intro' => 'A new job has been assigned to you.',
                'ctaLabel' => 'Open the work order',
            ]),
            'in-progress' => $this->emailUpdate($m, Recipients::tenantUsers($m->tenantId), [
                'subject' => "Work has started on {$m->code}",
                'heading' => 'Work has started',
                'intro' => 'A technician has begun work on your maintenance request.',
                'ctaLabel' => 'Track this request',
                'ctaUrl' => '/portal/maintenance',
            ]),
            'completed' => $this->emailUpdate($m, Recipients::tenantUsers($m->tenantId), [
                'subject' => "Please confirm the work on {$m->code}",
                'heading' => 'The work is done — please confirm',
                'intro' => 'The technician has marked this job complete. Confirm it was actually done so it can be closed out.',
                'ctaLabel' => 'Confirm the work',
                'ctaUrl' => '/portal/maintenance',
            ]),
            'verified' => $this->emailUpdate($m, $this->assignee($m)->merge(Recipients::tenantUsers($m->tenantId)), [
                'subject' => "{$m->code} verified",
                'heading' => 'Work order verified',
                'intro' => 'A manager has verified this work order.',
                'ctaLabel' => 'View the work order',
            ]),
            'closed' => $this->emailUpdate($m, Recipients::tenantUsers($m->tenantId), [
                'subject' => "{$m->code} closed",
                'heading' => 'Work order closed',
                'intro' => 'This maintenance request has been completed and closed.',
                'ctaLabel' => 'View the work order',
                'ctaUrl' => '/portal/maintenance',
            ]),
            default => null,
        };
    }

    /** @return Collection<int,User> */
    private function assignee(MaintenanceRequest $m): Collection
    {
        return $m->assignedToId
            ? User::query()->whereKey($m->assignedToId)->get()
            : collect();
    }

    /**
     * @param  iterable<int,User>  $recipients
     * @param  array<string,mixed>  $copy  subject, heading, intro, ctaLabel, [ctaUrl], [footnote]
     */
    private function emailUpdate(MaintenanceRequest $m, iterable $recipients, array $copy): void
    {
        $to = collect($recipients)->filter()->unique('id');
        if ($to->isEmpty()) {
            return;
        }

        Mail::to($to->all())->queue(new MaintenanceUpdateMail($copy + [
            'statusLabel' => Str::headline($m->status),
            'code' => $m->code,
            'title' => $m->title,
            'propertyName' => Property::query()->whereKey($m->propertyId)->value('name') ?? '—',
            'unitCode' => $m->unitId ? Unit::query()->whereKey($m->unitId)->value('code') : null,
            'ctaUrl' => FrontendUrl::to($copy['ctaUrl'] ?? "/maintenance/{$m->id}"),
        ]));
    }

    /** Append a triage / progress / final inspection round. */
    public function addRound(User $user, string $id, array $round): MaintenanceRequest
    {
        /** @var MaintenanceRequest $m */
        $m = $this->locate($user, $id);
        $this->assertCanOperate($user, $m);

        $round['id'] = 'rnd_'.Str::lower(Str::random(10));
        $round['byId'] ??= $user->id;

        $rounds = $m->rounds ?? [];
        $rounds[] = $round;
        $m->update(['rounds' => $rounds]);

        $stage = ['triage' => 'Triage', 'progress' => 'In-progress', 'final' => 'Post-repair'][$round['stage']] ?? 'Inspection';
        $this->audit->record($user, 'edit', 'Maintenance', $m->id, "{$m->code} — {$stage} round recorded ({$round['outcome']})");
        $this->notifications->push([
            'kind' => 'maintenance',
            'title' => "{$stage} round recorded",
            'body' => "{$m->code}: {$m->title} — ".($round['outcome'] === 'ok' ? 'no issues found' : 'issues flagged').'.',
            'forRole' => 'manager',
            'link' => "/maintenance/{$m->id}",
        ]);

        return $m->refresh();
    }
}
