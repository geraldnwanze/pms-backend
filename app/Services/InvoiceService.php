<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InvoiceService extends CrudService
{
    protected string $model = Invoice::class;

    protected array $searchable = ['code'];

    protected array $filterable = ['propertyId', 'tenantId', 'leaseId', 'unitId', 'status', 'cycle'];

    protected array $sortable = ['id', 'code', 'issueDate', 'dueDate', 'total', 'amountPaid', 'status'];

    protected string $defaultSort = 'issueDate';

    protected string $idPrefix = 'inv';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'INV';

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

    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        // The lease is what an invoice bills; the property, unit and tenant must be that lease's own.
        $lease = Lease::query()->find($data['leaseId'] ?? null);
        abort_unless(
            $lease && $lease->propertyId === ($data['propertyId'] ?? null)
                && $lease->unitId === ($data['unitId'] ?? null) && $lease->tenantId === ($data['tenantId'] ?? null),
            422,
            'The lease, unit, tenant and property of an invoice must all match.',
        );

        $lineItems = collect($data['lineItems'] ?? [])->map(function ($item) {
            $item['id'] ??= 'li_'.Str::lower(Str::random(10));

            return $item;
        })->all();

        // A late penalty is the server's to apply (see LateFeeService), never the client's. A discount may be given, but not
        // beyond what is billed.
        unset($data['penalty']);
        $subtotal = (int) collect($lineItems)->sum('amount');
        $discount = (int) ($data['discount'] ?? 0);
        abort_if($discount > $subtotal, 422, 'A discount cannot be more than the invoice.');

        $data['lineItems'] = $lineItems;
        $data['total'] = self::totalOf($subtotal, 0, $discount);
        $data['amountPaid'] = 0;
        $data['status'] ??= 'unpaid';

        return $data;
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        /** @var Invoice $record */
        abort_if($record->status === 'cancelled', 422, 'A cancelled invoice cannot be edited.');

        foreach (['leaseId', 'unitId', 'tenantId'] as $field) {
            abort_if(array_key_exists($field, $data) && $data[$field] !== $record->{$field}, 422, 'An invoice cannot be re-pointed at a different lease, unit or tenant.');
        }

        unset($data['penalty']);        // only the late-fee rules (or waiving one) change the penalty

        if (array_key_exists('lineItems', $data)) {
            $data['lineItems'] = collect($data['lineItems'])->map(function ($item) {
                $item['id'] ??= 'li_'.Str::lower(Str::random(10));

                return $item;
            })->all();
        }

        if (array_key_exists('lineItems', $data) || array_key_exists('discount', $data)) {
            $subtotal = (int) collect($data['lineItems'] ?? $record->lineItems ?? [])->sum('amount');
            $penalty = (int) $record->penalty;
            $discount = (int) ($data['discount'] ?? $record->discount ?? 0);
            abort_if($discount > $subtotal + $penalty, 422, 'A discount cannot be more than the invoice.');

            $data['total'] = self::totalOf($subtotal, $penalty, $discount);
        }

        return $data;
    }

    protected function afterUpdate(User $user, Model $record, array $data): void
    {
        /** @var Invoice $record */
        $this->recompute($record);      // a new total or due date can change what the invoice is
    }

    protected function afterCreate(User $user, Model $record, array $data): void
    {
        $this->audit->record($user, 'create', 'Invoice', $record->id, "Created invoice {$record->code}");
        $this->notifications->push([
            'kind' => 'rent',
            'title' => 'New invoice issued',
            'body' => "Invoice {$record->code} for ₦".number_format($record->total).' is due '.Dates::friendly($record->dueDate).'.',
            'forUserId' => $record->tenantId,
            'link' => "/portal/bills/{$record->id}",
        ]);
    }

    /** What is owed: the line items, plus any late penalty, less any discount (never below zero). */
    public static function totalOf(int $subtotal, int $penalty = 0, int $discount = 0): int
    {
        return max(0, $subtotal + $penalty - $discount);
    }

    /** Take a late fee off an invoice (staff with the approve right). It is not charged again. */
    public function waiveLateFee(User $user, string $id, string $reason): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->locate($user, $id);
        $this->authorize($user, 'approve', $invoice);

        $fee = (int) $invoice->penalty;
        abort_unless($fee > 0, 422, 'This invoice has no late fee to waive.');

        $invoice->forceFill([
            'penalty' => 0,
            'total' => self::totalOf((int) collect($invoice->lineItems)->sum('amount'), 0, (int) $invoice->discount),
            'lateFeeWaivedOn' => now()->toDateString(),
        ])->save();
        $this->recompute($invoice);         // with the fee gone, what was paid may now cover the invoice

        $this->audit->record($user, 'edit', 'Invoice', $invoice->id, 'Waived the ₦'.number_format($fee)." late fee on {$invoice->code}: {$reason}");
        $this->notifications->push([
            'kind' => 'rent',
            'title' => 'Late fee waived',
            'body' => 'The ₦'.number_format($fee)." late fee on invoice {$invoice->code} has been waived.",
            'forUserId' => $invoice->tenantId,
            'link' => "/portal/bills/{$invoice->id}",
        ]);

        return $invoice->refresh();
    }

    public function cancel(User $user, string $id): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->locate($user, $id);
        $this->authorize($user, 'update', $invoice);

        $invoice->update(['status' => 'cancelled']);
        $this->audit->record($user, 'edit', 'Invoice', $invoice->id, "Cancelled invoice {$invoice->code}");
        $this->notifications->push([
            'kind' => 'rent',
            'title' => 'Invoice cancelled',
            'body' => "Invoice {$invoice->code} has been cancelled — no payment is due.",
            'forUserId' => $invoice->tenantId,
            'link' => "/portal/bills/{$invoice->id}",
        ]);

        return $invoice->refresh();
    }

    /**
     * The status an invoice should have, from its paid amount and due date. An invoice is
     * only overdue once its due *day* has fully passed — due today is still "unpaid".
     * Mirrors `recomputeInvoice()` in the frontend's dataSlice.
     */
    public function statusFor(Invoice $invoice, ?Carbon $today = null): string
    {
        if ($invoice->status === 'cancelled') {
            return 'cancelled';
        }

        if ($invoice->amountPaid >= $invoice->total) {
            return 'fully-paid';
        }
        if ($invoice->amountPaid > 0) {
            return 'partially-paid';
        }

        $today ??= Carbon::today();

        return $invoice->dueDate && Carbon::parse($invoice->dueDate)->startOfDay()->lessThan($today->copy()->startOfDay())
            ? 'overdue'
            : 'unpaid';
    }

    /**
     * Re-derive an invoice's status and save the invoice. Callers set what they changed (the paid amount, the total)
     * and rely on this to persist it, so it saves whenever anything is pending — not only when the status moved: a
     * second part-payment leaves the invoice "partially-paid" but the new amount still has to be stored.
     */
    public function recompute(Invoice $invoice): void
    {
        $invoice->status = $this->statusFor($invoice);

        if ($invoice->isDirty()) {
            $invoice->save();
        }
    }
}
