<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Browser Web Push subscription management for the signed-in user.
 */
class PushController extends Controller
{
    use ApiResponses;

    public function __construct(private WebPushService $push) {}

    /** The VAPID public key the browser needs to subscribe. */
    public function key(): JsonResponse
    {
        return $this->ok(['publicKey' => config('webpush.vapid.public_key')], 'VAPID public key.');
    }

    /** Save the PushSubscription JSON the browser produced. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $sub = $this->push->store($request->user(), $data + ['userAgent' => $request->userAgent()]);

        return $this->created(['id' => $sub->id], 'Push notifications enabled on this device.');
    }

    /** Remove one subscription (unsubscribe / sign out on this device). */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        $this->push->forget($request->user(), $data['endpoint']);

        return $this->message('Push notifications disabled on this device.');
    }
}
