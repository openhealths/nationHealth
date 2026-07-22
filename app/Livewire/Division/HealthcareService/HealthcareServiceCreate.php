<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Enums\HealthcareService\Status;
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
            $validated = $this->form->doValidation();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        // Store in local database
        try {
            $validated['divisionId'] = $this->divisionId;
            $validated['legalEntityId'] = legalEntity()->id;
            $validated['status'] = Status::DRAFT;

            Repository::healthcareService()->store($this->form->formatForApi($validated));

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

        $validated = $this->validateForm();
        if (!$validated) {
            return;
        }

        $response = $this->createInEHealth($validated);
        if (!$response) {
            return;
        }

        try {
            $validated = $response->validate();
            Repository::healthcareService()->store($response->map($this->form->formatForApi($validated)));

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
