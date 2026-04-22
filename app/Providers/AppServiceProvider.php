<?php

declare(strict_types=1);

namespace App\Providers;

use App\Jobs\ClinicalImpressionSync;
use App\Jobs\ConditionSync;
use App\Jobs\DiagnosticReportSync;
use App\Jobs\EmployeeRoleSync;
use App\Jobs\EncounterFullSync;
use App\Jobs\EncounterShortSync;
use App\Jobs\EpisodeSync;
use App\Jobs\EquipmentSync;
use App\Jobs\HealthcareServiceSync;
use App\Jobs\ImmunizationSync;
use App\Jobs\ObservationSync;
use App\Rules\TranslatedDateValidator;
use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use Fruitcake\LaravelDebugbar\ServiceProvider as DebugbarServiceProvider;
use Illuminate\Bus\BatchRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Repositories\EHealthDatabaseBatchRepository;
use Illuminate\Bus\BatchFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->isLocal()) {
            $this->app->register(IdeHelperServiceProvider::class);
            $this->app->register(DebugbarServiceProvider::class);
        }

        /*
         * Extend BatchRepository to use EHealthDatabaseBatchRepository to allow store LegalEntity's ID into job_batches table
         * NOTE: don't remove '$_' this param. It will need to properly override the existing binding
         * $_ is an original DatabaseBatchRepository which code below trying to override (it don't use, so the name is just $_)
         */
        $this->app->extend(BatchRepository::class, function ($_, $app) {
            return new EHealthDatabaseBatchRepository(
                $app->make(BatchFactory::class),
                $app->make('db')->connection(),
                config('queue.batches.table', 'job_batches')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        Model::shouldBeStrict($this->app->isLocal());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->app['validator']->resolver(
            static fn ($translator, $data, $rules, $messages, $customAttributes) => new TranslatedDateValidator(
                $translator,
                $data,
                $rules,
                $messages,
                $customAttributes
            )
        );

        RateLimiter::for('ehealth-employee-get', function (object $job) {
            echo "Rate limiter set for user: " . $job->user->id . PHP_EOL; // TODO: remove it after testing

            return Limit::perMinute(config('ehealth.rate_limit.employee_request'))->by($job->user->id);
        });

        RateLimiter::for('ehealth-division-get', function (object $job) {
            echo "Rate limiter set for user: " . $job->user->id . PHP_EOL; // TODO: remove it after testing

            return Limit::perMinute(config('ehealth.rate_limit.division_request'))->by($job->user->id);
        });

        RateLimiter::for(
            'ehealth-healthcare-service-get',
            static fn (HealthcareServiceSync $job) => Limit::perMinute(config('ehealth.rate_limit.healthcare_service'))
                ->by($job->user->id)
        );

        RateLimiter::for(
            'ehealth-equipment-get',
            static fn (EquipmentSync $job) => Limit::perMinute(config('ehealth.rate_limit.equipment'))
                ->by($job->user->id)
        );

        RateLimiter::for(
            'ehealth-employee-role-get',
            static fn (EmployeeRoleSync $job) => Limit::perMinute(config('ehealth.rate_limit.employee_role'))
                ->by($job->user->id)
        );

        RateLimiter::for('ehealth-party-verification-get', function (object $job) {
            $limit = config('ehealth.rate_limit.party_request', 20);

            return Limit::perMinute($limit)->by($job->user->id);
        });

        RateLimiter::for('ehealth-employee-request-get', function (object $job) {
            return Limit::perMinute(19)->by($job->user->id);
        });

        RateLimiter::for('ehealth-declaration-get', function (object $job) {
            return Limit::perMinute(config('ehealth.rate_limit.declaration'))->by($job->user->id);
        });

        RateLimiter::for(
            'ehealth-episode-get',
            static fn (EpisodeSync $job) => Limit::perMinute(config('ehealth.rate_limit.episode'))->by($job->user->id)
        );

        RateLimiter::for(
            'ehealth-encounter-get',
            static fn (EncounterFullSync|EncounterShortSync $job) => Limit::perMinute(config('ehealth.rate_limit.encounter'))->by($job->user->id)
        );

        RateLimiter::for(
            'ehealth-clinical-impression-get',
            static fn (ClinicalImpressionSync $job) => Limit::perMinute(config('ehealth.rate_limit.clinical_impression'))->by($job->user->id)
        );

        RateLimiter::for(
            'ehealth-immunization-get',
            static fn (ImmunizationSync $job) => Limit::perMinute(config('ehealth.rate_limit.immunization'))->by($job->user->id)
        );

        RateLimiter::for(
            'ehealth-observation-get',
            static fn (ObservationSync $job) => Limit::perMinute(config('ehealth.rate_limit.observation'))->by($job->user->id)
        );

        RateLimiter::for(
            'ehealth-condition-get',
            static fn (ConditionSync $job) => Limit::perMinute(config('ehealth.rate_limit.condition'))->by($job->user->id)
        );

        RateLimiter::for(
            'ehealth-diagnostic-report-get',
            static fn (DiagnosticReportSync $job) => Limit::perMinute(config('ehealth.rate_limit.diagnostic_report'))->by($job->user->id)
        );
    }
}
