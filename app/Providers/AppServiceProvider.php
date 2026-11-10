<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Support\AfterResponse;
use App\Support\Settings;
use App\Support\Vault\CredentialVault;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /** Per IP, on the SPX webhook routes. */
    public const SPX_WEBHOOKS_PER_MINUTE = 6000;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CredentialVault::class);
        $this->app->scoped(Settings::class);
        $this->app->singleton(AfterResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
        $this->configureRateLimits();

        // A queue worker never terminates between jobs: its after-response work runs after each job instead.
        Event::listen(function (JobAttempted $event): void {
            if ($event->connectionName !== 'sync') {
                $this->app->make(AfterResponse::class)->flush();
            }
        });
    }

    /**
     * Per API token, per minute. Every limiter runs after AuthenticateClient, so the token is always the
     * authenticated one; the IP fallback only covers a route someone mounts without client.auth.
     */
    private function configureRateLimits(): void
    {
        $limits = ['api' => 300, 'api-coverage' => 60, 'api-create' => 120, 'api-labels' => 10];

        foreach ($limits as $name => $perMinute) {
            RateLimiter::for($name, function (Request $request) use ($perMinute) {
                $token = $request->attributes->get('api_token');

                return Limit::perMinute($perMinute)->by($token !== null ? 'token:' . $token->getKey() : 'ip:' . $request->ip());
            });
        }

        // Courier webhooks carry no API token: per IP.
        RateLimiter::for('courier-webhooks', fn (Request $request) => Limit::perMinute(300)->by('courier-webhooks:ip:' . $request->ip()));
        // SPX: a flood guard only (100 a second from one IP). SPX treats a 429 as a rejection and stops pushing, and a
        // sort centre can change hundreds of parcels a minute. What an unverified request may store is capped in
        // CourierWebhookController::UNVERIFIED_PER_MINUTE instead.
        RateLimiter::for('spx-webhooks', fn (Request $request) => Limit::perMinute(self::SPX_WEBHOOKS_PER_MINUTE)->by('spx-webhooks:ip:' . $request->ip()));

        // Viewing a courier secret: per admin.
        RateLimiter::for('secret-reveals', fn (Request $request) => Limit::perMinute(10)
            ->by('secret-reveals:admin:' . ($request->user()?->getAuthIdentifier() ?? 'ip:' . $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => 'Too many views. Try again in ' . max(1, (int) ($headers['Retry-After'] ?? 60)) . ' seconds.'],
                429,
                $headers + ['Cache-Control' => 'no-store'],
            )));
    }
}
