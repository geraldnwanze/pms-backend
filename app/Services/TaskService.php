<?php

namespace App\Services;

use App\Models\TaskItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TaskService extends CrudService
{
    protected string $model = TaskItem::class;

    protected array $searchable = ['title'];

    protected array $filterable = ['priority', 'done', 'assignedToId', 'propertyId'];

    protected array $sortable = ['id', 'dueDate', 'priority', 'done'];

    protected string $defaultSort = 'dueDate';

    protected string $idPrefix = 'task';

    protected array $createDefaults = ['done' => false, 'priority' => 'medium'];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        // Everyone sees what is assigned to them; a manager also sees the to-dos of the properties they run —
        // not other portfolios', and not other people's loose tasks.
        if ($user->role === 'manager') {
            $ids = $this->scope->visibleIds($user) ?? [];

            return $query->where(fn ($q) => $q->where('assignedToId', $user->id)->orWhereIn('propertyId', $ids ?: ['__none__']));
        }

        return $query->where('assignedToId', $user->id);
    }

    public function toggle(User $user, string $id): TaskItem
    {
        /** @var TaskItem $task */
        $task = $this->locate($user, $id);
        $this->authorize($user, 'update', $task);

        $task->update(['done' => ! $task->done]);

        return $task->refresh();
    }
}
