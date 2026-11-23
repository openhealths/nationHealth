<?php

declare(strict_types=1);

namespace App\Livewire\ServiceRequestRequest;

use App\Dto\ServiceRequest\Form as FormData;
use App\Dto\ServiceRequest\Model as ServiceRequestData;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Livewire\ServiceRequestRequest\Forms\ServiceRequestRequestForm;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ServiceRequestRequestEdit extends Component
{
    public ServiceRequestRequestForm $form;

    #[Locked]
    public int $personId;

    #[Locked]
    public int $serviceRequestId;

    #[Locked]
    public int $legalEntityId;

    #[Locked]
    public string $patientFullName;

    #[Locked]
    public string $requestVersion;

    public function mount(LegalEntity $legalEntity, Person $person, ServiceRequestRequest $serviceRequest): void
    {
        $this->personId = $person->id;
        $this->serviceRequestId = $serviceRequest->id;
        $this->legalEntityId = $legalEntity->id;
        $this->patientFullName = $person->fullName;
        $this->requestVersion = $serviceRequest->requestVersion();

        $serviceRequest->loadMissing('priority');
        $mapper = objectMapper();
        $data = $mapper->map($serviceRequest, FormData::class);
        $mapper->map($data, $this->form);
    }

    public function save(): void
    {
        $legalEntity = legalEntity();
        if ($legalEntity?->id !== $this->legalEntityId) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        try {
            $serviceRequest = Repository::serviceRequest()->withLockedRequest(
                $this->personId,
                $this->serviceRequestId,
                fn (ServiceRequestRequest $request) => $this->saveLockedRequest($request, $legalEntity)
            );
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        if ($serviceRequest === null) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->requestVersion = $serviceRequest->requestVersion();
        Session::flash('success', __('referrals.draft_saved'));
    }

    protected function saveLockedRequest(ServiceRequestRequest $request, LegalEntity $legalEntity): ?ServiceRequestRequest
    {
        if (Auth::user()->cannot('update', [$request, $request->person, $legalEntity])) {
            return null;
        }

        $this->form->validateForUpdate($request, $this->requestVersion);
        $mapper = objectMapper();
        $data = $mapper->map($this->form, ServiceRequestData::class);

        return Repository::serviceRequest()->saveRequest($data, $request, $mapper);
    }

    public function render(): View
    {
        return view('livewire.service-request-request.service-request-request-edit');
    }
}
