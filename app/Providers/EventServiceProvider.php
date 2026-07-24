<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\LogLockout;
use App\Events\EHealthUserLogin;
use App\Events\LegalEntityCreate;
use Illuminate\Auth\Events\Lockout;
use App\Events\EhealthUserVerified;
use App\Listeners\eHealth\EmployeeCreate;
use App\Listeners\SendUserCredentialsListener;
use App\Listeners\OnRegularLoginSyncronization;
use App\Listeners\SyncUserRolesAfterVerification;
use App\Listeners\FirstLoginOwnerSynchronization;
use App\Listeners\eHealth\EmployeeRequestActualize;
use App\Listeners\PartyVerificationSyncStatusOnLogin;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Lockout::class => [
            LogLockout::class
        ],
        LegalEntityCreate::class => [
            SendUserCredentialsListener::class
        ],
        EhealthUserVerified::class => [
            SyncUserRolesAfterVerification::class
        ],
        EHealthUserLogin::class => [
            FirstLoginOwnerSynchronization::class,
            OnRegularLoginSyncronization::class,
            EmployeeCreate::class,
            EmployeeRequestActualize::class,
            PartyVerificationSyncStatusOnLogin::class
        ]
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
