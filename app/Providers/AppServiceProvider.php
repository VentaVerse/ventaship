<?php

namespace App\Providers;

use App\Support\Settings;
use App\Support\Vault\CredentialVault;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CredentialVault::class);
        $this->app->scoped(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
