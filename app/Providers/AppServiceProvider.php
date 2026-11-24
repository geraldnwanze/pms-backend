<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\EntityPolicy;
use App\Support\EntityRegistry;
use App\Support\Modules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One read of the module switches per request (or per console command), however many places ask.
        $this->app->scoped(Modules::class);
    }

    public function boot(): void
    {
        // Every v1 resource authorizes through the single EntityPolicy.
        foreach (EntityRegistry::all() as $entity) {
            Gate::policy($entity['model'], EntityPolicy::class);
        }

        Gate::policy(User::class, EntityPolicy::class);

        $this->configureRateLimiting();
    }

    /**
     * Brute-force / abuse limits on the unauthenticated auth endpoints. Each pairs a
     * tight per-account(+IP) bucket with a looser per-IP one, so one attacker can't
     * grind a single account nor spray many.
     */
    private function configureRateLimiting(): void
    {
        $account = fn (Request $r) => strtolower((string) $r->input('email')).'|'.$r->ip();

        // Password sign-in only. The local passwordless demo login (no email) is unthrottled.
        RateLimiter::for('auth-login', fn (Request $r) => $r->filled('email')
            ? [Limit::perMinute(5)->by($account($r)), Limit::perMinute(20)->by($r->ip())]
            : Limit::none());

        RateLimiter::for('auth-register', fn (Request $r) => [
            Limit::perMinute(5)->by($r->ip()),
            Limit::perHour(30)->by($r->ip()),
        ]);

        // Confirming an address: the token is the credential, so guard it against guessing.
        RateLimiter::for('email-verify', fn (Request $r) => [
            Limit::perMinute(10)->by($r->ip()),
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
        ]);

        // Asking for another confirmation email: each one is mail sent to a third party's inbox.
        RateLimiter::for('email-resend', fn (Request $r) => [
            Limit::perMinute(2)->by($r->user()?->id ?: $r->ip()),
            Limit::perHour(6)->by($r->user()?->id ?: $r->ip()),
        ]);

        // Forgot/reset password: also caps how many reset emails one address can be sent.
        RateLimiter::for('auth-recovery', fn (Request $r) => [
            Limit::perMinute(3)->by($account($r)),
            Limit::perMinute(20)->by($r->ip()),
        ]);
    }
}
