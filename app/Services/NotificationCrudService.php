<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class NotificationCrudService extends CrudService
{
    protected string $model = Notification::class;

    protected array $searchable = ['title', 'body'];

    protected array $filterable = ['kind', 'read', 'forRole', 'forUserId'];

    protected array $sortable = ['id', 'createdAt', 'kind', 'read'];

    protected string $defaultSort = '-createdAt';

    protected string $idPrefix = 'ntf';

    protected array $nowOnCreate = ['createdAt'];

    protected array $createDefaults = ['read' => false];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            $q->where(fn ($b) => $b->whereNull('forRole')->whereNull('forUserId'))
                ->orWhere('forUserId', $user->id)
                ->orWhere('forRole', $user->role);

            foreach ($user->tenantRecordIds() as $tid) {
                $q->orWhere('forUserId', $tid);
            }
        });
    }

    public function markRead(User $user, string $id): Notification
    {
        /** @var Notification $notification */
        $notification = $this->locate($user, $id);
        $notification->update(['read' => true]);

        return $notification->refresh();
    }

    public function markAllRead(User $user): int
    {
        return $this->scopeQuery(Notification::query(), $user)
            ->where('read', false)
            ->update(['read' => true]);
    }
}
