<?php

namespace App\Services;

use App\Models\Inspection;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The security deposit, held in trust for the tenant. A lease carries the deposit it requires (`securityDeposit`)
 * and a ledger of what actually happened to it:
 *
 *   received  — money taken from the tenant (up to the amount the lease requires)
 *   deduction — money kept for a reason (damage, etc.) when the tenancy ends
 *   offset    — money applied to the lease's unpaid invoices (arrears), oldest first
 *   refund    — the rest, given back
 *
 * Held = received − deductions − refunds. It is the tenant's money, not income: it is kept out of the billing and
 * collection figures. Settling happens once, after the lease is over.
 */
class DepositService extends CrudService
{
    protected string $model = Lease::class;

    public function __construct(PropertyScope $scope, private AuditService $audit, private NotificationService $notifications, private InvoiceService $invoices)
    {
        parent::__construct($scope);
    }

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }

    /** What is held, from a ledger. */
    public static function heldOf(?array $ledger): int
    {
        $held = 0;
        foreach ($ledger ?? [] as $e) {
            $held += $e['type'] === 'received' ? (int) $e['amount'] : -(int) $e['amount'];
        }

        return $held;
    }

    public static function receivedOf(?array $ledger): int
    {
        return (int) collect($ledger ?? [])->where('type', 'received')->sum('amount');
    }

    public function summary(User $user, string $leaseId): array
    {
        /** @var Lease $lease */
        $lease = $this->locate($user, $leaseId);

        return $this->present($lease, $this->mayManage($user, $lease));
    }

    public function receive(User $user, string $leaseId, int $amount, string $method, ?string $reference, ?string $date): array
    {
        $lease = $this->lockedFor($user, $leaseId, function (Lease $lease) use ($amount) {
            abort_unless(in_array($lease->status, LeaseService::CURRENT, true), 422, 'A deposit can only be collected on a current lease.');
            $required = (int) $lease->securityDeposit;
            abort_unless($required > 0, 422, 'This lease does not ask for a security deposit.');
            $left = $required - self::receivedOf($lease->depositLedger);
            abort_if($left <= 0, 422, 'The full deposit has already been received.');
            abort_if($amount > $left, 422, 'That is more than the deposit still outstanding (₦'.number_format($left).').');
        }, function (Lease $lease, array $ledger) use ($amount, $method, $reference, $date, $user) {
            $ledger[] = $this->entry('received', $amount, $date, "Deposit received by {$method}".($reference ? " ({$reference})" : ''), $user);

            return ['depositLedger' => $ledger];
        });

        $this->audit->record($user, 'edit', 'Lease', $lease->id, 'Received ₦'.number_format($amount)." security deposit on {$lease->code}");
        $this->notifications->push([
            'kind' => 'lease', 'title' => 'Security deposit received',
            'body' => 'We have received ₦'.number_format($amount)." of your security deposit for lease {$lease->code}.",
            'forUserId' => $lease->tenantId, 'link' => '/portal/lease',
        ]);

        return $this->present($lease->refresh(), true);
    }

    /**
     * Close out the deposit once the tenancy is over: keep what is owed for the stated reasons, give the rest back.
     *
     * @param  array<int,array{reason:string,amount:int}>  $deductions
     */
    public function settle(User $user, string $leaseId, array $deductions, bool $applyToArrears = false): array
    {
        $kept = (int) collect($deductions)->sum('amount');

        $lease = $this->lockedFor($user, $leaseId, function (Lease $lease) use ($kept) {
            abort_if(in_array($lease->status, LeaseService::CURRENT, true), 422, 'The deposit is settled after the lease has ended.');
            abort_if($lease->depositSettledOn, 422, 'This deposit has already been settled.');
            $held = self::heldOf($lease->depositLedger);
            abort_unless($held > 0, 422, 'There is no deposit held on this lease.');
            abort_if($kept > $held, 422, 'The deductions (₦'.number_format($kept).') are more than the deposit held (₦'.number_format($held).').');
        }, function (Lease $lease, array $ledger) use ($deductions, $user, $applyToArrears) {
            foreach ($deductions as $d) {
                $ledger[] = $this->entry('deduction', (int) $d['amount'], null, $d['reason'], $user);
            }
            if ($applyToArrears) {
                foreach ($this->arrearsFor($lease->id, self::heldOf($ledger)) as ['invoice' => $invoice, 'amount' => $amount]) {
                    $this->payFromDeposit($invoice, $amount, $user);
                    $ledger[] = $this->entry('offset', $amount, null, "Applied to invoice {$invoice->code}", $user);
                }
            }
            $refund = self::heldOf($ledger);
            if ($refund > 0) {
                $ledger[] = $this->entry('refund', $refund, null, 'Balance refunded to the tenant', $user);
            }

            return ['depositLedger' => $ledger, 'depositSettledOn' => now()->toDateString()];
        });

        $refund = (int) collect($lease->depositLedger)->where('type', 'refund')->sum('amount');
        $offset = (int) collect($lease->depositLedger)->where('type', 'offset')->sum('amount');
        $applied = $offset > 0 ? ', ₦'.number_format($offset).' applied to unpaid invoices' : '';
        $this->audit->record($user, 'edit', 'Lease', $lease->id, "Settled the deposit on {$lease->code}: ₦".number_format($kept).' kept'.$applied.', ₦'.number_format($refund).' refunded');
        $this->notifications->push([
            'kind' => 'lease', 'title' => 'Security deposit settled',
            'body' => "Your deposit for lease {$lease->code}: ₦".number_format($kept).' kept'.$applied.', ₦'.number_format($refund).' refunded to you.',
            'forUserId' => $lease->tenantId, 'link' => '/portal/lease',
        ]);

        return $this->present($lease->refresh(), true);
    }

    /**
     * The unpaid invoices of a lease that `$available` naira of deposit would be applied to: oldest due first, each up to
     * what it still owes (late fee included). An invoice with a payment still being verified is left for that payment.
     *
     * @return array<int,array{invoice:Invoice,amount:int}>
     */
    private function arrearsFor(string $leaseId, int $available): array
    {
        $open = Payment::query()->where('leaseId', $leaseId)->whereIn('status', ['initiated', 'pending-verification'])->pluck('invoiceId')->all();
        $plan = [];

        $invoices = Invoice::query()->where('leaseId', $leaseId)->where('status', '!=', 'cancelled')->orderBy('dueDate')->orderBy('id')->lockForUpdate()->get();
        foreach ($invoices as $invoice) {
            $owed = (int) $invoice->total - (int) $invoice->amountPaid;
            if ($available <= 0) {
                break;
            }
            if ($owed <= 0 || in_array($invoice->id, $open, true)) {
                continue;
            }
            $amount = min($owed, $available);
            $plan[] = ['invoice' => $invoice, 'amount' => $amount];
            $available -= $amount;
        }

        return $plan;
    }

    /** Pay (part of) an invoice out of the deposit: a payment on record, so the invoice's history shows where it came from. */
    private function payFromDeposit(Invoice $invoice, int $amount, User $by): void
    {
        $id = 'pay_'.Str::lower(Str::random(12));
        $invoice->amountPaid = (int) $invoice->amountPaid + $amount;
        $this->invoices->recompute($invoice);
        $invoice->save();

        Payment::query()->create([
            'id' => $id, 'code' => 'PAY-'.Str::upper(substr($id, -5)),
            'invoiceId' => $invoice->id, 'leaseId' => $invoice->leaseId, 'unitId' => $invoice->unitId,
            'propertyId' => $invoice->propertyId, 'tenantId' => $invoice->tenantId,
            'type' => 'other', 'method' => 'deposit', 'amount' => $amount, 'paymentDate' => now()->toDateString(),
            'reference' => 'Security deposit', 'description' => "Paid from the security deposit on settlement of lease {$invoice->leaseId}",
            'status' => 'approved', 'reviewedById' => $by->id, 'reviewedAt' => now()->toIso8601String(),
            'balanceAfter' => max(0, (int) $invoice->total - (int) $invoice->amountPaid), 'submittedAt' => now()->toIso8601String(),
        ]);
    }

    /** Run a change to the ledger under a row lock, so two clerks cannot both collect (or refund) the same money. */
    private function lockedFor(User $user, string $leaseId, callable $check, callable $change): Lease
    {
        $lease = $this->locate($user, $leaseId);
        abort_unless($this->mayManage($user, $lease), 403, 'Only a manager or finance officer of this property can handle the deposit.');

        return DB::transaction(function () use ($leaseId, $check, $change) {
            $lease = Lease::query()->whereKey($leaseId)->lockForUpdate()->firstOrFail();
            $check($lease);
            $lease->forceFill($change($lease, $lease->depositLedger ?? []))->save();

            return $lease;
        });
    }

    private function mayManage(User $user, Lease $lease): bool
    {
        return $user->isAdmin() || in_array($user->roleOnProperty($lease->propertyId), ['manager', 'finance'], true);
    }

    private function entry(string $type, int $amount, ?string $date, string $note, User $by): array
    {
        return ['id' => 'dep_'.Str::lower(Str::random(10)), 'type' => $type, 'amount' => $amount, 'date' => $date ?: now()->toDateString(), 'note' => $note, 'byId' => $by->id];
    }

    /** What the lease's unpaid invoices still owe in total, for staff to see before settling. */
    private function arrearsOwed(string $leaseId): int
    {
        return (int) Invoice::query()->where('leaseId', $leaseId)->where('status', '!=', 'cancelled')->get(['total', 'amountPaid'])->sum(fn ($i) => max(0, (int) $i->total - (int) $i->amountPaid));
    }

    private function present(Lease $lease, bool $staff): array
    {
        $ledger = $lease->depositLedger ?? [];
        $required = (int) $lease->securityDeposit;
        $received = self::receivedOf($ledger);

        return [
            'leaseId' => $lease->id,
            'required' => $required,
            'received' => $received,
            'outstanding' => max(0, $required - $received),
            'held' => self::heldOf($ledger),
            'settledOn' => $lease->depositSettledOn,
            'ledger' => array_values($ledger),
            'arrears' => $staff && ! $lease->depositSettledOn ? $this->arrearsOwed($lease->id) : null,
            // What the move-out inspection recorded as damage: a starting point for the deductions, for staff only.
            'inspectionDeductions' => $staff ? (int) Inspection::query()->where('unitId', $lease->unitId)->where('type', 'move-out')->where('status', 'completed')->orderByDesc('completedDate')->value('depositDeductions') : null,
        ];
    }
}
