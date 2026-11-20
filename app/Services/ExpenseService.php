<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExpenseService extends CrudService
{
    protected string $model = Expense::class;

    protected array $searchable = ['code', 'description'];

    protected array $filterable = ['propertyId', 'category', 'status', 'vendorId'];

    protected array $sortable = ['id', 'code', 'date', 'amount', 'status'];

    protected string $defaultSort = '-date';

    protected string $idPrefix = 'exp';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'EXP';

    protected array $createDefaults = ['status' => 'pending'];

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

    /** Where an expense may go from each status. Money only moves after approval; nothing leaves `paid`. */
    private const TRANSITIONS = [
        'pending' => ['approved', 'rejected'],
        'approved' => ['paid', 'rejected'],
        'rejected' => ['pending'],          // resubmitted after a correction
        'paid' => [],
    ];

    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);
        $data['status'] = 'pending';        // never born approved or paid, whatever the client sends
        $data['approvedById'] = null;

        return $data;
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        abort_if(
            array_key_exists('status', $data) && $data['status'] !== $record->status,
            422,
            'Change an expense\'s status through /expenses/{id}/status.',
        );
        abort_unless($record->status === 'pending', 422, 'An expense that has been approved, rejected or paid can no longer be edited.');
        unset($data['status']);

        return $data;
    }

    protected function afterUpdate(User $user, Model $record, array $data): void
    {
        Vendor::refreshTotals($record->vendorId);
    }

    public function delete(User $user, string $id): void
    {
        $vendorId = Expense::query()->whereKey($id)->value('vendorId');

        parent::delete($user, $id);

        Vendor::refreshTotals($vendorId);
    }

    protected function afterCreate(User $user, Model $record, array $data): void
    {
        Vendor::refreshTotals($record->vendorId);

        $this->audit->record($user, 'create', 'Expense', $record->id,
            "Recorded expense {$record->code} (₦".number_format($record->amount).')');
    }

    /** @param 'pending'|'approved'|'rejected'|'paid' $status */
    public function setStatus(User $user, string $id, string $status): Expense
    {
        /** @var Expense $expense */
        $expense = $this->locate($user, $id);
        $this->authorize($user, in_array($status, ['approved', 'rejected'], true) ? 'approve' : 'update', $expense);

        abort_unless(
            in_array($status, self::TRANSITIONS[$expense->status] ?? [], true),
            422,
            "An expense that is '{$expense->status}' cannot become '{$status}'.",
        );

        $expense->status = $status;
        // The decision-maker is whoever approved or rejected it; paying doesn't replace them.
        $expense->approvedById = match ($status) {
            'pending' => null,
            'paid' => $expense->approvedById ?? $user->id,
            default => $user->id,
        };
        $expense->save();
        Vendor::refreshTotals($expense->vendorId);

        $this->audit->record($user, $status === 'approved' ? 'approve' : 'edit', 'Expense', $expense->id,
            "{$expense->code} → {$status}");

        if (in_array($status, ['approved', 'rejected', 'paid'], true)) {
            $this->notifications->push([
                'kind' => 'system',
                'title' => 'Expense '.$status,
                'body' => "Expense {$expense->code} (₦".number_format($expense->amount).") was {$status}.",
                'forRole' => 'manager',
                'link' => '/expenses',
            ]);
        }

        return $expense->refresh();
    }
}
