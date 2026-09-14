<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CalendarService extends CrudService
{
    protected string $model = CalendarEvent::class;

    protected array $searchable = ['title'];

    protected array $filterable = ['kind', 'refId'];

    protected array $sortable = ['id', 'date', 'kind'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'evt';

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $query;
    }
}
