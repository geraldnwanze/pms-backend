<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a browser Web Push for one user-targeted in-app notification.
 * Queued by NotificationService::push() when `forUserId` is set.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 30;

    public function __construct(public string $notificationId) {}

    public function handle(WebPushService $push): void
    {
        $n = Notification::query()->find($this->notificationId);
        if (! $n || ! $n->forUserId) {
            return;
        }

        // `forUserId` is a users.id for staff, or a tenants.id for portal users.
        $userIds = User::query()
            ->where('id', $n->forUserId)
            ->orWhere('tenantId', $n->forUserId)
            ->pluck('id')
            ->all();

        if ($userIds === []) {
            return;
        }

        $push->sendToUsers($userIds, [
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->link ?: '/notifications',
            'tag' => $n->id,
            'kind' => $n->kind,
        ]);
    }
}
