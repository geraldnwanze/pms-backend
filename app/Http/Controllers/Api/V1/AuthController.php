<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\Lease;
use App\Models\PropertyMember;
use App\Models\User;
use App\Services\AuditService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponses;

    private const AVATAR_COLORS = ['#059669', '#0891b2', '#7c3aed', '#db2777', '#ea580c', '#2563eb', '#65a30d', '#c026d3', '#0d9488', '#d97706'];

    public function __construct(private AuditService $audit) {}

    /**
     * Register a plain account. New users hold the `user` role and see no
     * property data until they either create a property (becoming its owner)
     * or accept an invitation to one.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => ['sometimes', 'string'],
            // Self-service sign-up can never pick a role — least of all admin.
            // A new account is a plain `user`; it becomes an owner by creating a
            // property, or a tenant/staff/maintenance by accepting an invitation.
            'role' => ['prohibited'],
        ]);

        $email = Str::lower($data['email']);

        $user = User::query()->create([
            'id' => 'usr_'.Str::lower(Str::random(12)),
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => 'user',
            'avatarColor' => self::AVATAR_COLORS[crc32($email) % count(self::AVATAR_COLORS)],
            'twoFactorEnabled' => false,
            'status' => 'active',
            'lastLoginAt' => now()->format('Y-m-d\TH:i:s'),
        ]);

        // Attach any invitations already waiting for this email address.
        PropertyMember::query()
            ->whereNull('userId')
            ->where('email', $email)
            ->update(['userId' => $user->id]);

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        $this->audit->record($user, 'create', 'User', $user->id, "Registered account for {$user->name}");

        return $this->ok(['token' => $token, 'user' => $user], 'Account created.', null, 201);
    }

    /**
     * Start a password reset. Always returns the same message; in local/testing
     * the reset token is included in the payload so the flow is exercisable
     * without a mail server (MAIL_MAILER=log otherwise).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $broker = Password::broker();
        $user = $broker->getUser(['email' => Str::lower($data['email'])]);
        $payload = null;

        if ($user) {
            // This is an API + SPA; there is no server-rendered reset page, so we
            // mint the token here and email a link to the SPA's /reset-password
            // route. In local/testing we also return it directly for convenience.
            $token = $broker->createToken($user);
            $expires = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
            $resetUrl = FrontendUrl::to('/reset-password?token='.$token.'&email='.urlencode($user->email));

            Mail::to($user->email)->queue(new PasswordResetMail([
                'email' => $user->email,
                'resetUrl' => $resetUrl,
                'expiresMinutes' => $expires,
            ]));

            if (app()->environment('local', 'testing', 'development')) {
                $payload = [
                    'email' => $user->email,
                    'reset_token' => $token,
                    'reset_url' => $resetUrl,
                ];
            }
        }

        return $this->ok($payload, 'If that email is registered, a password reset link has been sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete(); // sign out other sessions
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return $this->message('Your password has been reset. You can now sign in.');
    }

    /**
     * Exchange credentials for a Sanctum bearer token.
     *
     * Also accepts { "role": "<role>" } in non-production environments so the
     * frontend's demo role-picker keeps working without passwords.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without_all:role,userId', 'email'],
            'password' => ['required_with:email', 'string'],
            'role' => ['required_without_all:email,userId', 'string', 'in:admin,owner,manager,agent,tenant,maintenance,finance'],
            'userId' => ['sometimes', 'string'],
            'device_name' => ['sometimes', 'string'],
        ]);

        if (! empty($data['role']) || ! empty($data['userId'])) {
            abort_unless(app()->environment('local', 'testing', 'development'), 403, 'Passwordless login is disabled.');

            if (! empty($data['userId'])) {
                $user = User::query()->findOrFail($data['userId']);
            } else {
                $user = self::demoAccountFor($data['role']);
                abort_if($user === null, 404, "No demo account for role {$data['role']}.");
            }
        } else {
            $user = User::query()->where('email', $data['email'])->first();

            if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
                throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
            }
        }

        $user->forceFill(['lastLoginAt' => now()->format('Y-m-d\TH:i:s')])->save();
        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        $this->audit->record($user, 'login', 'Auth', $user->id, "{$user->name} signed in");

        return $this->ok([
            'token' => $token,
            'user' => $user,
        ], 'Signed in.');
    }

    /** One representative account per role, for the demo login screen. Local only. */
    public function demoUsers(): JsonResponse
    {
        abort_unless(app()->environment('local', 'testing', 'development'), 404);

        $order = array_flip(User::ROLE_PRECEDENCE);

        $users = User::query()
            ->where('status', 'active')
            ->get(['id', 'name', 'email', 'role', 'title', 'avatarColor'])
            ->sortBy(fn (User $u) => sprintf('%02d-%s', $order[$u->role] ?? 99, $u->name))
            ->map(fn (User $u) => $u->only(['id', 'name', 'email', 'role', 'title', 'avatarColor']))
            ->values();

        return $this->ok($users, 'Demo accounts.');
    }

    /**
     * Deterministic representative account for a role. For tenants, pick one
     * whose tenant record has an active lease so the portal has data.
     */
    private static function demoAccountFor(string $role): ?User
    {
        $query = User::query()->where('role', $role)->where('status', 'active')->orderBy('id');

        if ($role === 'tenant') {
            $withLease = (clone $query)
                ->whereIn('tenantId', Lease::query()->where('status', 'active')->pluck('tenantId'))
                ->first();

            if ($withLease) {
                return $withLease;
            }
        }

        return $query->first();
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok($request->user(), 'Current user.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->message('Signed out.');
    }
}
