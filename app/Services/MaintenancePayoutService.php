<?php

namespace App\Services;

use App\Mail\MaintenancePayoutMail;
use App\Models\Expense;
use App\Models\MaintenancePayout;
use App\Models\MaintenanceRequest;
use App\Models\PropertyMember;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\Recipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Technician payouts for completed, tenant-confirmed work orders.
 *
 *   technician  → request()   (once the tenant has confirmed)
 *   owner/mgr   → approve() / reject()
 *   owner/mgr   → markPaid()  (records the payout + a linked expense)
 *
 * A real bank transfer (Paystack Transfer API) can be wired onto markPaid()
 * later; for now "paid" is an in-app record with a full audit trail.
 */
class MaintenancePayoutService
{
    /** Work-order states from which a payout may be requested. */
    private const PAYABLE_STATES = ['tenant-confirmed', 'verified', 'closed'];

    /** Payout states that block a fresh request on the same work order. */
    private const OPEN_STATES = ['requested', 'approved', 'paid'];

    public function __construct(
        private PropertyScope $scope,
        private AuditService $audit,
        private NotificationService $notifications,
    ) {}

    /** Rows visible to a user: those on their properties, plus their own. */
    public function visibleTo(User $user)
    {
        if ($user->isTenantOnly()) {
            return collect();
        }

        $ids = $this->scope->visibleIds($user);

        return MaintenancePayout::query()
            ->when($ids !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('propertyId', $ids ?: ['__none__'])->orWhere('staffId', $user->id)
            ))
            ->orderByDesc('requestedAt')
            ->get();
    }

    public function request(User $user, string $maintenanceId, ?int $amount, ?string $note): MaintenancePayout
    {
        /** @var MaintenanceRequest $m */
        $m = MaintenanceRequest::query()->findOrFail($maintenanceId);

        abort_unless($this->scope->canSee($user, $m->propertyId), 403, 'Work order not found.');
        abort_unless(
            $user->isAdmin() || $user->id === $m->assignedToId,
            403,
            'Only the assigned technician can request payment for this job.',
        );
        abort_unless(
            in_array($m->status, self::PAYABLE_STATES, true),
            422,
            'The tenant must confirm the job is done before you can request payment.',
        );
        abort_if(
            MaintenancePayout::query()->where('maintenanceId', $m->id)->whereIn('status', self::OPEN_STATES)->exists(),
            409,
            'A payout request for this work order already exists.',
        );

        $materials = (int) $m->materialsCost;
        $labour = (int) $m->labourCost;
        $amount = $amount ?: ($materials + $labour);
        abort_if($amount <= 0, 422, 'Enter the amount you are owed.');

        // Snapshot the technician's payout bank details from their membership on
        // this property, if they've set them.
        $membership = PropertyMember::query()
            ->where('propertyId', $m->propertyId)
            ->where('userId', $m->assignedToId ?? $user->id)
            ->where('role', 'maintenance')
            ->where('status', 'active')
            ->first();

        $id = 'mpo_'.Str::lower(Str::random(12));
        $payout = MaintenancePayout::query()->create([
            'id' => $id,
            'code' => 'MPO-'.Str::upper(substr($id, -5)),
            'maintenanceId' => $m->id,
            'propertyId' => $m->propertyId,
            'staffId' => $m->assignedToId ?? $user->id,
            'amount' => $amount,
            'materialsAmount' => $materials ?: null,
            'labourAmount' => $labour ?: null,
            'note' => $note,
            'status' => 'requested',
            'requestedAt' => now()->toIso8601String(),
            'createdAt' => now()->toIso8601String(),
            'bankName' => $membership?->bankName,
            'bankAccountName' => $membership?->bankAccountName,
            'bankAccountNumber' => $membership?->bankAccountNumber,
            'bankCode' => $membership?->bankCode,
        ]);

        $this->audit->record($user, 'create', 'MaintenancePayout', $payout->id,
            'Requested ₦'.number_format($amount)." for {$m->code}");

        $payers = $this->payers($m->propertyId);
        foreach ($payers as $payer) {
            $this->notifications->push([
                'kind' => 'maintenance',
                'title' => 'Payment requested',
                'body' => "{$payout->code}: ₦".number_format($amount)." for work order {$m->code}.",
                'forUserId' => $payer->id,
                'link' => "/maintenance/{$m->id}",
            ]);
        }
        $this->emailPayout($payout, $payers, [
            'subject' => "Payment requested: {$payout->code}",
            'heading' => 'A technician has requested payment',
            'intro' => 'Review and approve the payout for this completed, tenant-confirmed work order.',
            'ctaLabel' => 'Review the payout',
            'note' => $note,
        ]);

        return $payout->refresh();
    }

    public function approve(User $user, string $id): MaintenancePayout
    {
        $payout = $this->locateForPayer($user, $id);
        $this->requireStatus($payout, 'requested');

        $payout->forceFill([
            'status' => 'approved',
            'approvedById' => $user->id,
            'approvedAt' => now()->toIso8601String(),
        ])->save();

        $this->audit->record($user, 'approve', 'MaintenancePayout', $payout->id, "Approved {$payout->code}");
        $this->notifications->push([
            'kind' => 'maintenance', 'title' => 'Payout approved',
            'body' => "{$payout->code} for ₦".number_format($payout->amount).' was approved. Payment is on the way.',
            'forUserId' => $payout->staffId, 'link' => "/maintenance/{$payout->maintenanceId}",
        ]);
        $this->emailPayout($payout, $this->staffOf($payout), [
            'subject' => "Payout {$payout->code} approved",
            'heading' => 'Your payout was approved',
            'intro' => 'Payment for your work is on the way.',
            'ctaLabel' => 'View the payout',
        ]);

        return $payout->refresh();
    }

    public function reject(User $user, string $id, string $reason): MaintenancePayout
    {
        $payout = $this->locateForPayer($user, $id);
        abort_unless(in_array($payout->status, ['requested', 'approved'], true), 422,
            "Payout {$payout->code} is '{$payout->status}' and can't be rejected.");

        $payout->forceFill([
            'status' => 'rejected',
            'rejectedReason' => $reason,
            'approvedById' => $user->id,
            'approvedAt' => now()->toIso8601String(),
        ])->save();

        $this->audit->record($user, 'reject', 'MaintenancePayout', $payout->id, "Rejected {$payout->code}: {$reason}");
        $this->notifications->push([
            'kind' => 'maintenance', 'title' => 'Payout rejected',
            'body' => "{$payout->code} was rejected: {$reason}",
            'forUserId' => $payout->staffId, 'link' => "/maintenance/{$payout->maintenanceId}",
        ]);
        $this->emailPayout($payout, $this->staffOf($payout), [
            'subject' => "Payout {$payout->code} was declined",
            'heading' => 'Your payout was declined',
            'intro' => 'The payout request was not approved. Speak to the property manager if you think this is a mistake.',
            'ctaLabel' => 'View the payout',
            'reason' => $reason,
        ]);

        return $payout->refresh();
    }

    public function markPaid(User $user, string $id, ?string $method, ?string $reference): MaintenancePayout
    {
        $payout = $this->locateForPayer($user, $id);
        $this->requireStatus($payout, 'approved');

        /** @var MaintenanceRequest|null $m */
        $m = MaintenanceRequest::query()->find($payout->maintenanceId);
        $staff = User::query()->find($payout->staffId);

        $expenseId = 'exp_'.Str::lower(Str::random(12));
        Expense::query()->create([
            'id' => $expenseId,
            'code' => 'EXP-'.Str::upper(substr($expenseId, -5)),
            'propertyId' => $payout->propertyId,
            'category' => 'repairs',
            'vendorId' => null,
            'description' => "Maintenance payout {$payout->code} — ".($staff?->name ?? 'technician')
                .($m ? " ({$m->code})" : ''),
            'amount' => $payout->amount,
            'date' => now()->toDateString(),
            'status' => 'paid',
            'approvedById' => $user->id,
        ]);

        $payout->forceFill([
            'status' => 'paid',
            'paidById' => $user->id,
            'paidAt' => now()->toIso8601String(),
            'paymentMethod' => $method ?: 'bank-transfer',
            'paymentReference' => $reference,
            'expenseId' => $expenseId,
        ])->save();

        $this->audit->record($user, 'edit', 'MaintenancePayout', $payout->id,
            "Paid {$payout->code} (₦".number_format($payout->amount).')');
        $this->notifications->push([
            'kind' => 'maintenance', 'title' => "You've been paid",
            'body' => 'Payout '.$payout->code.' of ₦'.number_format($payout->amount).' has been paid.',
            'forUserId' => $payout->staffId, 'link' => "/maintenance/{$payout->maintenanceId}",
        ]);
        $this->emailPayout($payout, $this->staffOf($payout), [
            'subject' => "You've been paid: {$payout->code}",
            'heading' => "You've been paid",
            'intro' => 'Payment for your work has been released ('
                .Str::of((string) $payout->paymentMethod)->replace('-', ' ')->headline().').',
            'ctaLabel' => 'View the payout',
        ]);

        return $payout->refresh();
    }

    // ---- internals ------------------------------------------------------

    private function locateForPayer(User $user, string $id): MaintenancePayout
    {
        /** @var MaintenancePayout $payout */
        $payout = MaintenancePayout::query()->findOrFail($id);
        $this->assertCanPay($user, $payout->propertyId);

        return $payout;
    }

    private function assertCanPay(User $user, ?string $propertyId): void
    {
        if ($user->isAdmin() || $user->role === 'finance') {
            return;
        }

        $role = $propertyId ? $user->roleOnProperty($propertyId) : null;
        abort_unless(in_array($role, ['owner', 'manager'], true), 403,
            'Only a property owner, manager, finance or admin can act on payouts.');
    }

    private function requireStatus(MaintenancePayout $payout, string $expected): void
    {
        abort_unless($payout->status === $expected, 422,
            "Payout {$payout->code} is '{$payout->status}'; this action needs '{$expected}'.");
    }

    /** @return Collection<int,User> */
    private function payers(string $propertyId)
    {
        return Recipients::propertyManagers($propertyId)
            ->merge(User::query()->where('role', 'finance')->get())
            ->unique('id')
            ->values();
    }

    /** @return Collection<int,User> */
    private function staffOf(MaintenancePayout $payout)
    {
        return User::query()->whereKey($payout->staffId)->get();
    }

    /**
     * @param  iterable<int,User>  $recipients
     * @param  array<string,mixed>  $copy  subject, heading, intro, ctaLabel, [note], [reason]
     */
    private function emailPayout(MaintenancePayout $payout, iterable $recipients, array $copy): void
    {
        $to = collect($recipients)->filter()->unique('id');
        if ($to->isEmpty()) {
            return;
        }

        $workOrder = MaintenanceRequest::query()->whereKey($payout->maintenanceId)->value('code') ?? $payout->maintenanceId;
        $staffName = User::query()->whereKey($payout->staffId)->value('name') ?? 'Technician';

        Mail::to($to->all())->queue(new MaintenancePayoutMail($copy + [
            'code' => $payout->code,
            'amount' => '₦'.number_format((int) $payout->amount),
            'workOrderCode' => $workOrder,
            'staffName' => $staffName,
            'ctaUrl' => FrontendUrl::to("/maintenance/{$payout->maintenanceId}"),
        ]));
    }
}
