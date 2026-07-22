<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Livewire\Division\Forms\HealthcareServiceForm as Form;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use App\Traits\WorkTimeUtilities;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Throwable;

class HealthcareServiceUpdate extends Component
{
    use WorkTimeUtilities;
    use FormTrait;

    public Form $form;

    /**
     * Determine is user has policy to update.
     *
     * @var bool
     */
    public bool $canUpdate;

    public bool $working = true;

    public bool $isDisabled = false;

    public int $healthcareServiceId;

    public string $healthcareServiceUuid;

    public function mount(LegalEntity $legalEntity, Division $division, HealthcareService $healthcareService): void
    {
        $this->healthcareServiceId = $healthcareService->id;
        $this->healthcareServiceUuid = $healthcareService->uuid;

        $this->form->fill($healthcareService->only(['comment', 'availableTime', 'notAvailable']));

        if ($this->form->availableTime) {
            $this->form->availableTime = Arr::toCamelCase($this->form->availableTime);
        }

        $this->canUpdate = Auth::user()->can('update', $healthcareService);
    }

    public function update(): void
    {
        if (!$this->canUpdate) {
            Session::flash('error', __('healthcare-services.policy.update'));

            return;
        }

        try {
            $validated = $this->form->doUpdateValidation();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::healthcareService()->update(
                $this->healthcareServiceUuid,
                $this->form->formatForApi($validated)
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating a healthcare service');

            return;
        }

        try {
            $validated = $response->validate();

            $validated = Arr::only($validated, ['comment', 'coverage_area', 'available_time', 'not_available', 'ehealth_updated_at', 'ehealth_updated_by']);
            $validated['id'] = $this->healthcareServiceId;

            Repository::healthcareService()->update($validated, false);

            Session::flash('success', __('healthcare-services.success.updated'));
            $this->redirectRoute('healthcare-service.index', [legalEntity()], navigate: true);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to update healthcare service');

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.division.healthcare-service.healthcare-service-update');
    }
}
