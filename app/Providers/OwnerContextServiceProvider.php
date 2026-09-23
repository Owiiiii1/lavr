<?php

namespace App\Providers;

use App\Models\OwnerContextSource;
use App\Policies\OwnerContextSourcePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OwnerContextServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(OwnerContextSource::class, OwnerContextSourcePolicy::class);
    }
}
