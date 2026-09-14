<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /** One representative account per role, for the login role-picker. */
    public function demoUsers(): JsonResponse
    {
        $roles = ['admin', 'owner', 'manager', 'finance', 'agent', 'maintenance', 'tenant'];

        $users = collect($roles)
            ->map(fn ($role) => User::where('role', $role)->where('status', 'active')->first())
            ->filter()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'title' => $u->title,
                'avatarColor' => $u->avatarColor,
            ])
            ->values();

        return response()->json($users);
    }

    /**
     * Demo login. Accepts any of:
     *  - { "role": "admin" }              → first active user with that role
     *  - { "userId": "mgr_2" }            → that specific user (account switch)
     *  - { "email": "...", "password": "..." } → credential check (all demo
     *                                            accounts use "password")
     *
     * Returns the user plus a token, which for this demo is simply the user id
     * (sent back as the X-User-Id header on subsequent requests).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', 'string'],
            'userId' => ['sometimes', 'string'],
            'email' => ['sometimes', 'email'],
            'password' => ['sometimes', 'string'],
        ]);

        $user = null;

        if (! empty($data['userId'])) {
            $user = User::find($data['userId']);
        } elseif (! empty($data['email'])) {
            $user = User::where('email', $data['email'])->first();
            if ($user && ! Hash::check($data['password'] ?? '', $user->password)) {
                return response()->json(['message' => 'Invalid credentials.'], 422);
            }
        } elseif (! empty($data['role'])) {
            $user = User::where('role', $data['role'])->where('status', 'active')->first();
        }

        if (! $user) {
            return response()->json(['message' => 'No matching account.'], 422);
        }

        $user->forceFill(['lastLoginAt' => now()->format('Y-m-d\TH:i:s')])->save();

        AuditLog::query()->create([
            'id' => 'aud_'.Str::lower(Str::random(9)),
            'action' => 'login',
            'entity' => 'System',
            'entityId' => $user->id,
            'userId' => $user->id,
            'summary' => "{$user->name} signed in",
            'timestamp' => now()->toIso8601String(),
            'ip' => $request->ip(),
            'device' => 'Web',
        ]);

        return response()->json([
            'token' => $user->id,
            'user' => $user,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function logout(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
