<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
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

        $lineItems = collect($data['lineItems'] ?? [])->map(function ($item) {
            $item['id'] ??= 'li_'.Str::lower(Str::random(10));

            return $item;
        })->all();

        $data['lineItems'] = $lineItems;
        $data['total'] = (int) collect($lineItems)->sum('amount');
        $data['amountPaid'] = 0;
        $data['status'] ??= 'unpaid';

        return $data;
    }

    protected function afterCreate(User $user, Model $record, array $data): void
    {
        $this->audit->record($user, 'create', 'Invoice', $record->id, "Created invoice {$record->code}");
        $this->notifications->push([
            'kind' => 'rent',
            'title' => 'New invoice issued',
            'body' => "Invoice {$record->code} for ₦".number_format($record->total)." is due {$record->dueDate}.",
            'forUserId' => $record->tenantId,
            'link' => '/portal/bills',
        ]);
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
            'link' => '/portal/bills',
        ]);

        return $invoice->refresh();
    }

    /**
     * Re-derive an invoice's status from its paid amount and due date.
     * Mirrors `recomputeInvoice()` in the frontend's dataSlice.
     */
    public function recompute(Invoice $invoice): void
    {
        if ($invoice->status === 'cancelled') {
            return;
        }

        if ($invoice->amountPaid >= $invoice->total) {
            $invoice->status = 'fully-paid';
        } elseif ($invoice->amountPaid > 0) {
            $invoice->status = 'partially-paid';
        } else {
            $invoice->status = Carbon::parse($invoice->dueDate)->isPast() ? 'overdue' : 'unpaid';
        }

        $invoice->save();
    }
}
