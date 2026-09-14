<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a new in-app notification is created. Broadcasts over Reverb to
 * the channel(s) the notification targets so open sessions receive it live:
 *
 *  - `forUserId` set  → private `notifications.user.{id}`
 *  - `forRole` set    → private `notifications.role.{role}`
 *  - neither (global)  → private `notifications.all`
 *
 * Channel authorization lives in routes/channels.php.
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification) {}

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        if ($this->notification->forUserId) {
            return [new PrivateChannel("notifications.user.{$this->notification->forUserId}")];
        }

        if ($this->notification->forRole) {
            return [new PrivateChannel("notifications.role.{$this->notification->forRole}")];
        }

        return [new PrivateChannel('notifications.all')];
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        $n = $this->notification;

        return [
            'id' => $n->id,
            'kind' => $n->kind,
            'title' => $n->title,
            'body' => $n->body,
            'createdAt' => $n->createdAt,
            'read' => (bool) $n->read,
            'forRole' => $n->forRole,
            'forUserId' => $n->forUserId,
            'link' => $n->link,
        ];
    }
}
