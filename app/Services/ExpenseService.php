<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
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

    protected function afterCreate(User $user, Model $record, array $data): void
    {
        $this->audit->record($user, 'create', 'Expense', $record->id,
            "Recorded expense {$record->code} (₦".number_format($record->amount).')');
    }

    /** @param 'pending'|'approved'|'rejected'|'paid' $status */
    public function setStatus(User $user, string $id, string $status): Expense
    {
        /** @var Expense $expense */
        $expense = $this->locate($user, $id);
        $this->authorize($user, $status === 'approved' ? 'approve' : 'update', $expense);

        $expense->status = $status;
        if ($status !== 'pending') {
            $expense->approvedById = $user->id;
        }
        $expense->save();

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
