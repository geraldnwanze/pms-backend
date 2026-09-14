<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Stores browser Web Push subscriptions and delivers VAPID-signed pushes to
 * them. Delivery is invoked from a queued job, never inline with a request.
 */
class WebPushService
{
    /** Save (or refresh) a subscription for the given user. */
    public function store(User $user, array $data): PushSubscription
    {
        return PushSubscription::query()->updateOrCreate(
            ['userId' => $user->id, 'endpoint' => $data['endpoint']],
            [
                'id' => 'push_'.Str::lower(Str::random(16)),
                'publicKey' => $data['keys']['p256dh'] ?? $data['publicKey'] ?? '',
                'authToken' => $data['keys']['auth'] ?? $data['authToken'] ?? '',
                'contentEncoding' => $data['contentEncoding'] ?? 'aesgcm',
                'userAgent' => $data['userAgent'] ?? null,
                'createdAt' => now()->toIso8601String(),
                'lastUsedAt' => now()->toIso8601String(),
            ],
        );
    }

    /** Drop one subscription (browser unsubscribed or signed out). */
    public function forget(User $user, string $endpoint): void
    {
        PushSubscription::query()
            ->where('userId', $user->id)
            ->where('endpoint', $endpoint)
            ->delete();
    }

    /**
     * Push a payload to every subscription owned by the given users. Expired
     * subscriptions (404/410 from the push service) are pruned.
     *
     * @param  array<int,string>  $userIds
     * @param  array<string,mixed>  $payload  title, body, url, tag, kind
     */
    public function sendToUsers(array $userIds, array $payload): void
    {
        if (! $this->configured()) {
            Log::warning('WebPush: VAPID keys not configured, skipping push.');

            return;
        }

        $subs = PushSubscription::query()->whereIn('userId', array_values(array_unique($userIds)))->get();
        if ($subs->isEmpty()) {
            return;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => (string) config('webpush.vapid.subject'),
            'publicKey' => (string) config('webpush.vapid.public_key'),
            'privateKey' => (string) config('webpush.vapid.private_key'),
        ]]);

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $queued = 0;

        foreach ($subs as $sub) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'publicKey' => $sub->publicKey,
                        'authToken' => $sub->authToken,
                        'contentEncoding' => $sub->contentEncoding ?: 'aesgcm',
                    ]),
                    $body,
                    ['TTL' => (int) config('webpush.ttl', 43200)],
                );
                $queued++;
            } catch (\Throwable $e) {
                // Malformed keys — the row can never receive a push; drop it.
                Log::warning('WebPush: dropping unusable subscription', ['id' => $sub->id, 'error' => $e->getMessage()]);
                $sub->delete();
            }
        }

        if ($queued === 0) {
            return;
        }

        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = (string) $report->getRequest()->getUri();

                if ($report->isSuccess()) {
                    PushSubscription::query()->where('endpoint', $endpoint)
                        ->update(['lastUsedAt' => now()->toIso8601String()]);

                    continue;
                }

                if ($report->isSubscriptionExpired()) {
                    PushSubscription::query()->where('endpoint', $endpoint)->delete();
                }

                Log::warning('WebPush delivery failed', [
                    'endpoint' => Str::limit($endpoint, 60),
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('WebPush flush error', ['error' => $e->getMessage()]);
        }
    }

    public function configured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }
}
