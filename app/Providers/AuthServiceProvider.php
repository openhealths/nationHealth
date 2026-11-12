<?php

declare(strict_types=1);

namespace App\Providers;

use App\Policies\DeviceDefinitionPolicy;
use App\Policies\DrugPolicy;
use App\Policies\ServiceCatalogPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;
use App\Auth\EHealth\Guards\EHealthGuard;
use App\Auth\EHealth\Services\TokenStorage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Cookie\QueueingFactory;
use App\Auth\EHealth\Providers\EHealthUserProvider;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any authentication / authorization services.
     *
     * The API is consumed by the application's own frontend only: the token cookie is read on routes that run
     * the web middleware, whose EncryptCookies has already decrypted it, and no OAuth endpoints are exposed.
     *
     * @return void
     */
    public function register(): void
    {
        parent::register();

        Passport::withoutCookieEncryption();
        Passport::ignoreRoutes();
    }

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::guessPolicyNamesUsing(function ($modelClass) {
            return 'App\\Policies\\' . class_basename($modelClass) . 'Policy';
        });

        Auth::extend('ehealth', static function (Application $app, string $name, array $config) {
            $provider = Auth::createUserProvider($config['provider']);
            $tokenStorage = $app->make(TokenStorage::class);

            $guard = new EHealthGuard($name, $provider, $app['session.store'], $app['request'], $tokenStorage);

            $guard->setCookieJar($app->make(QueueingFactory::class));

            return $guard;
        });

        Auth::provider('ehealth_user_provider', static function (Application $app, array $config) {
            return new EHealthUserProvider($app['hash'], $config['model']);
        });

        // Register policies for dictionary access
        Gate::define('view-drugs', [DrugPolicy::class, 'view']);
        Gate::define('view-device-definitions', [DeviceDefinitionPolicy::class, 'view']);
        Gate::define('view-service-catalog', [ServiceCatalogPolicy::class, 'view']);
    }
}
