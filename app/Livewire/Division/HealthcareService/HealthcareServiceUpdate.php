<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Dto\HealthcareService\EhealthUpdate as EhealthUpdateData;
use App\Dto\HealthcareService\Model as HealthcareServiceData;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
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
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class HealthcareServiceUpdate extends Component
{
    use FormTrait;
    use WorkTimeUtilities;

    public Form $form;

    public bool $working = true;

    public bool $isDisabled = false;

    #[Locked]
    public int $healthcareServiceId;

    public function mount(LegalEntity $legalEntity, Division $division, HealthcareService $healthcareService): void
    {
        $this->healthcareServiceId = $healthcareService->id;

        $this->form->fill($healthcareService->only(['comment', 'availableTime', 'notAvailable']));

        if ($this->form->availableTime) {
            $this->form->availableTime = Arr::toCamelCase($this->form->availableTime);
        }
    }

    /**
     * Healthcare service being updated.
     *
     * @return HealthcareService
     */
    #[Computed]
    public function healthcareService(): HealthcareService
    {
        return HealthcareService::findOrFail($this->healthcareServiceId);
    }

    public function update(): void
    {
        if (Auth::user()->cannot('update', $this->healthcareService)) {
            Session::flash('error', __('healthcare-services.policy.update'));

            return;
        }

        try {
            $this->form->doUpdateValidation();
        } catch (ValidationException $exception) {
            Session::flash('error', $this->form->notificationMessage($exception->validator->errors()));
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $healthcareServiceEhealthMapped = objectMapper()->map($this->form, EhealthUpdateData::class)->toArray();

        try {
            $response = EHealth::healthcareService()->update($this->healthcareService->uuid, $healthcareServiceEhealthMapped);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating a healthcare service');

            return;
        }

        try {
            $healthcareServiceData = objectMapper()->map($response->validate(), HealthcareServiceData::class);

            Repository::healthcareService()->save($healthcareServiceData, $this->healthcareService);

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
