<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\EntityPolicy;
use App\Support\EntityRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Every v1 resource authorizes through the single EntityPolicy.
        foreach (EntityRegistry::all() as $entity) {
            Gate::policy($entity['model'], EntityPolicy::class);
        }

        Gate::policy(User::class, EntityPolicy::class);
    }
}
