<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            (string) config('services.pixelforge.base_url'),
            config('services.pixelforge.api_key'),
            (int) config('services.pixelforge.timeout', 15),
        ));

        $this->app->bind(WebhookSignature::class, fn () => new WebhookSignature(
            (string) config('services.pixelforge.webhook_secret'),
            (int) config('services.pixelforge.webhook_tolerance', 300),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
