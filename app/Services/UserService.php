<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserService extends CrudService
{
    protected string $model = User::class;

    protected array $searchable = ['name', 'email', 'title'];

    protected array $filterable = ['role', 'status', 'ownerId'];

    protected array $sortable = ['id', 'name', 'email', 'role', 'status'];

    protected string $defaultSort = 'name';

    protected string $idPrefix = 'usr';

    protected array $createDefaults = ['status' => 'active', 'twoFactorEnabled' => false];

    public function __construct(PropertyScope $scope, private AuditService $audit)
    {
        parent::__construct($scope);
    }

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('id', $user->id);
    }

    protected function prepareForCreate(User $actor, array $data): array
    {
        $data = parent::prepareForCreate($actor, $data);
        $data['password'] = Hash::make($data['password'] ?? 'password');
        $data = array_diff_key($data, array_flip(self::VERIFICATION_COLUMNS)); // verification is earned, never supplied

        return $data;
    }

    private const VERIFICATION_COLUMNS = ['emailVerifiedAt', 'emailVerifyTokenHash', 'emailVerifyExpiresAt'];

    protected function prepareForUpdate(User $actor, Model $record, array $data): array
    {
        $this->guardSuperAdmin($actor, $record, $data);

        $data = array_diff_key($data, array_flip(self::VERIFICATION_COLUMNS));

        // A different address has not been proven yet.
        if (isset($data['email']) && Str::lower($data['email']) !== Str::lower((string) $record->email)) {
            $data['emailVerifiedAt'] = null;
            $data['emailVerifyTokenHash'] = null;
            $data['emailVerifyExpiresAt'] = null;
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        return $data;
    }

    protected function afterCreate(User $actor, Model $record, array $data): void
    {
        $this->audit->record($actor, 'create', 'User', $record->id, "Created user {$record->name} ({$record->role})");
    }

    protected function beforeDelete(User $user, Model $record): void
    {
        $this->guardSuperAdmin($user, $record, deleting: true);
    }

    /**
     * A superadmin outranks an admin, so only a superadmin may edit a superadmin account at all (otherwise an admin could set its
     * password and sign in as it). Even they cannot change its role or status, or delete it, through the app: those are done with
     * `php artisan pms:make-superadmin`, so the people who can switch modules are decided by whoever runs the server.
     */
    private function guardSuperAdmin(User $actor, Model $record, array $data = [], bool $deleting = false): void
    {
        if (! $record instanceof User || ! $record->isSuperAdmin()) {
            return;
        }

        abort_unless($actor->isSuperAdmin(), 403, 'Only a superadmin can change a superadmin account.');
        abort_if($deleting, 422, 'A superadmin account cannot be deleted here: take the role away first with php artisan pms:make-superadmin --revoke.');
        abort_if(array_key_exists('role', $data) || array_key_exists('status', $data), 422, "A superadmin's role and status are managed with php artisan pms:make-superadmin.");
    }

    protected function afterUpdate(User $actor, Model $record, array $data): void
    {
        // Deactivating someone, or changing their password, signs them out everywhere.
        if (($data['status'] ?? null) === 'inactive' || ! empty($data['password'])) {
            $record->tokens()->delete();
        }

        $action = array_key_exists('role', $data) ? 'permission-change' : 'edit';
        $this->audit->record($actor, $action, 'User', $record->id, "Updated {$record->name}");
    }
}
