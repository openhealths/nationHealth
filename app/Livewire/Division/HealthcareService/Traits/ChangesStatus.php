<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService\Traits;

use App\Classes\eHealth\EHealth;
use App\Dto\HealthcareService\Model as HealthcareServiceData;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\HealthcareService;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * Activation and deactivation of a healthcare service, available from the list and from its details page.
 */
trait ChangesStatus
{
    /**
     * Activate the inactive healthcare service in eHealth and store the result.
     *
     * @param  HealthcareService  $healthcareService
     * @return void
     */
    public function activate(HealthcareService $healthcareService): void
    {
        if (Auth::user()->cannot('activate', $healthcareService)) {
            Session::flash('error', __('healthcare-services.policy.activate'));

            return;
        }

        try {
            $response = EHealth::healthcareService()->activate($healthcareService->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle("Error when activate $healthcareService->uuid a healthcare service");

            return;
        }

        try {
            $healthcareServiceData = objectMapper()->map($response->validate(), HealthcareServiceData::class);

            Repository::healthcareService()->save($healthcareServiceData, $healthcareService);

            Session::flash('success', __('healthcare-services.success.activated'));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, "Failed to activate $healthcareService->uuid healthcare service");

            return;
        }
    }

    /**
     * Deactivate the active healthcare service in eHealth and store the result.
     *
     * @param  HealthcareService  $healthcareService
     * @return void
     */
    public function deactivate(HealthcareService $healthcareService): void
    {
        if (Auth::user()->cannot('deactivate', $healthcareService)) {
            Session::flash('error', __('healthcare-services.policy.deactivate'));

            return;
        }

        try {
            $response = EHealth::healthcareService()->deactivate($healthcareService->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle("Error when deactivating $healthcareService->uuid a healthcare service");

            return;
        }

        try {
            $healthcareServiceData = objectMapper()->map($response->validate(), HealthcareServiceData::class);

            Repository::healthcareService()->save($healthcareServiceData, $healthcareService);

            Session::flash('success', __('healthcare-services.success.deactivated'));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, "Failed to deactivate $healthcareService->uuid healthcare service");

            return;
        }
    }
}
