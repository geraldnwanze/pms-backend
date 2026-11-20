<?php

namespace App\Services;

use App\Mail\VerifyEmailMail;
use App\Models\PropertyMember;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Proves an account controls its email address.
 *
 * A random 64-character token is emailed as a link; we keep only its SHA-256 and an expiry, so a database leak
 * cannot be turned into verified accounts. Property invitations are addressed to an email, which is why this
 * matters: until the address is verified an account may not see or accept them (see MembershipService).
 */
class EmailVerificationService
{
    public const EXPIRES_HOURS = 24;

    public function __construct(private AuditService $audit) {}

    /**
     * Email a fresh link. Any earlier link stops working. Does nothing for an already-verified account.
     *
     * @return string|null the raw token (so local/testing responses can expose it), or null when nothing was sent
     */
    public function send(User $user): ?string
    {
        if ($user->hasVerifiedEmail()) {
            return null;
        }

        $token = Str::random(64);

        $user->forceFill([
            'emailVerifyTokenHash' => hash('sha256', $token),
            'emailVerifyExpiresAt' => now()->addHours(self::EXPIRES_HOURS)->toIso8601String(),
        ])->save();

        Mail::to($user->email)->queue(new VerifyEmailMail([
            'email' => $user->email,
            'name' => $user->name,
            'verifyUrl' => $this->url($user->email, $token),
            'expiresHours' => self::EXPIRES_HOURS,
        ]));

        return $token;
    }

    public function url(string $email, string $token): string
    {
        return FrontendUrl::to('/verify-email?token='.$token.'&email='.urlencode($email));
    }

    /**
     * Confirm an address from its emailed link. One generic error for every failure (unknown address, wrong or
     * reused token, expired) so the endpoint cannot be used to discover which addresses are registered.
     */
    public function verify(string $email, string $token): User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($email))])->first();

        $valid = $user
            && $user->emailVerifyTokenHash
            && hash_equals($user->emailVerifyTokenHash, hash('sha256', $token))
            && $user->emailVerifyExpiresAt
            && Carbon::parse($user->emailVerifyExpiresAt)->isFuture();

        if (! $valid) {
            throw ValidationException::withMessages(['token' => ['This verification link is invalid or has expired. Request a new one.']]);
        }

        $this->markVerified($user);
        $this->audit->record($user, 'edit', 'User', $user->id, 'Verified email address');

        return $user->refresh();
    }

    /**
     * Record that the address is confirmed and hand over any invitations that were waiting for it.
     * Also used when a password reset succeeds — reading the reset email proves the same thing.
     */
    public function markVerified(User $user): void
    {
        $user->forceFill([
            'emailVerifiedAt' => $user->emailVerifiedAt ?: now()->toIso8601String(),
            'emailVerifyTokenHash' => null,
            'emailVerifyExpiresAt' => null,
        ])->save();

        PropertyMember::query()
            ->whereNull('userId')
            ->whereRaw('lower(email) = ?', [Str::lower($user->email)])
            ->update(['userId' => $user->id]);
    }
}
