<?php

namespace App\Providers;

use App\Support\Settings;
use App\Support\Vault\CredentialVault;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimits();
    }

    /**
     * Per API key, per minute (base plan §2). Every limiter runs after AuthenticateClient, so the key is always the
     * authenticated one; the IP fallback only covers a route someone mounts without client.auth.
     */
    private function configureRateLimits(): void
    {
        $limits = ['api' => 300, 'api-coverage' => 60, 'api-create' => 120, 'api-labels' => 10];

        foreach ($limits as $name => $perMinute) {
            RateLimiter::for($name, function (Request $request) use ($perMinute) {
                $key = $request->attributes->get('api_key');

                return Limit::perMinute($perMinute)->by($key !== null ? (string) $key->getKey() : 'ip:' . $request->ip());
            });
        }
    }
}
