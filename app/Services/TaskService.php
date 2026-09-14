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
        return in_array($user->role, ['admin', 'manager'], true)
            ? $query
            : $query->where('assignedToId', $user->id);
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
