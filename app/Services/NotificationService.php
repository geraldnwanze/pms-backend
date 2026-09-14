<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Jobs\SendPushNotification;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Creates and reads in-app notifications. Mirrors the frontend's `notify()`
 * helper in `src/store/dataSlice.ts`.
 */
class NotificationService
{
    /**
     * Create an in-app notification and broadcast it in real time to whoever it
     * targets (a user, a role, or everyone).
     *
     * Pass a deterministic `id` to make the call idempotent — the daily
     * reminder sweeps use `rent:{invoiceId}:{date}` style keys so re-running
     * them the same day updates rather than duplicates.
     *
     * @param  array{kind:string,title:string,body:string,forRole?:string,forUserId?:string,link?:string,id?:string}  $data
     */
    public function push(array $data): Notification
    {
        $attributes = [
            'kind' => $data['kind'],
            'title' => $data['title'],
            'body' => $data['body'],
            'createdAt' => now()->toIso8601String(),
            'read' => false,
            'forRole' => $data['forRole'] ?? null,
            'forUserId' => $data['forUserId'] ?? null,
            'link' => $data['link'] ?? null,
        ];

        $notification = isset($data['id'])
            ? Notification::query()->updateOrCreate(['id' => $data['id']], $attributes)
            : Notification::query()->create(['id' => 'ntf_'.Str::lower(Str::random(12))] + $attributes);

        if ($notification->wasRecentlyCreated) {
            NotificationCreated::dispatch($notification);

            // Browser push, but only for notifications aimed at a specific person.
            if ($notification->forUserId) {
                SendPushNotification::dispatch($notification->id);
            }
        }

        return $notification;
    }

    /** Notifications addressed to this user, their role, or broadcast to all. */
    public function forUser(User $user): Builder
    {
        return Notification::query()->where(function (Builder $q) use ($user) {
            $q->where(fn (Builder $b) => $b->whereNull('forRole')->whereNull('forUserId'))
                ->orWhere('forUserId', $user->id)
                ->orWhere('forRole', $user->role);

            foreach ($user->tenantRecordIds() as $tid) {
                $q->orWhere('forUserId', $tid);
            }
        });
    }
}
