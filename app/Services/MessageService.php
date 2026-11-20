<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MessageService extends CrudService
{
    protected string $model = Message::class;

    protected array $searchable = ['body'];

    protected array $filterable = ['threadId', 'fromUserId', 'toUserId', 'read'];

    protected array $sortable = ['id', 'createdAt', 'threadId', 'read'];

    protected string $defaultSort = 'createdAt';

    protected string $idPrefix = 'msg';

    protected array $nowOnCreate = ['createdAt'];

    protected array $createDefaults = ['read' => false];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('fromUserId', $user->id)->orWhere('toUserId', $user->id));
    }

    protected function prepareForCreate(User $user, array $data): array
    {
        $data['fromUserId'] = $user->id;     // a message is always from whoever sends it

        return parent::prepareForCreate($user, $data);
    }

    /** Mark every message in a thread addressed to the current user as read. */
    public function markThreadRead(User $user, string $threadId): int
    {
        return Message::query()
            ->where('threadId', $threadId)
            ->where('toUserId', $user->id)
            ->update(['read' => true]);
    }
}
