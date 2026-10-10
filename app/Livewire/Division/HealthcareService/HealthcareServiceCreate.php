<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Dto\HealthcareService\Model as HealthcareServiceData;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class HealthcareServiceCreate extends HealthcareServiceComponent
{
    public function mount(LegalEntity $legalEntity, Division $division): void
    {
        $this->baseMount($legalEntity, $division);
    }

    public function createLocally(): void
    {
        if (Auth::user()->cannot('create', HealthcareService::class)) {
            Session::flash('error', __('healthcare-services.policy.create'));

            return;
        }

        try {
            $this->form->doValidation();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $healthcareServiceData = HealthcareServiceData::fromSource($this->form);

            Repository::healthcareService()->saveMapped(
                $healthcareServiceData,
                new HealthcareService(['division_id' => $this->divisionId]),
                legalEntity()
            );

            Session::flash('success', __('healthcare-services.success.draft_created'));
            $this->redirectRoute('healthcare-service.index', [legalEntity(), $this->divisionId], navigate: true);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store healthcare service');

            return;
        }
    }

    public function create(): void
    {
        if (Auth::user()->cannot('create', HealthcareService::class)) {
            Session::flash('error', __('healthcare-services.policy.create'));

            return;
        }

        if (!$this->validateForm()) {
            return;
        }

        $response = $this->createInEHealth();
        if (!$response) {
            return;
        }

        try {
            $healthcareServiceData = HealthcareServiceData::fromSource($response->validate());

            Repository::healthcareService()->saveMapped(
                $healthcareServiceData,
                new HealthcareService(['division_id' => $this->divisionId]),
                legalEntity()
            );

            Session::flash('success', __('healthcare-services.success.created'));
            $this->redirectRoute('healthcare-service.index', [legalEntity(), $this->divisionId], navigate: true);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store healthcare service');

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.division.healthcare-service.healthcare-service-create');
    }
}
