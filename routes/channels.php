<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Notification channels
|--------------------------------------------------------------------------
|
| `NotificationCreated` broadcasts to one of these depending on who the
| notification targets. A client may only subscribe to:
|
|  - its own user channel
|  - one of its own tenant-record channels (portal logins)
|  - the channel for its current role
|  - the global channel (any authenticated user)
|
*/

Broadcast::channel('notifications.user.{id}', function (User $user, string $id) {
    return $user->id === $id || in_array($id, $user->tenantRecordIds(), true);
});

Broadcast::channel('notifications.role.{role}', function (User $user, string $role) {
    return $user->role === $role;
});

Broadcast::channel('notifications.all', fn (User $user) => (bool) $user);
