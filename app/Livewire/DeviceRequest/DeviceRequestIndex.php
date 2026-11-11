<?php

declare(strict_types=1);

namespace App\Livewire\DeviceRequest;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;

use App\Exceptions\EHealth\EHealthException;
use App\Livewire\DeviceRequest\Forms\DeviceRequestSearchForm;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class DeviceRequestIndex extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $legalEntityId;

    #[Locked]
    public ?int $personId = null;

    #[Locked]
    public bool $patientContext = false;

    public string $patientSearch = '';

    #[Locked]
    public array $patients = [];

    public DeviceRequestSearchForm $filters;

    public string $requesterEntitySearch = '';

    #[Locked]
    public array $legalEntityOptions = [];

    #[Locked]
    public array $requests = [];

    #[Locked]
    public array $drafts = [];

    #[Locked]
    public array $catalog = [];

    #[Locked]
    public array $selected = [];

    #[Locked]
    public array $dispenses = [];

    #[Locked]
    public array $dispenseDetails = [];

    #[Locked]
    public bool $dispensesLoaded = false;

    #[Locked]
    public array $reasons = [];

    public string $reason = '';

    public string $reasonText = '';

    #[Locked]
    public string $phone = '';

    public bool $phoneConfirmed = false;

    public int $page = 1;

    public int $totalPages = 1;

    #[Locked]
    public ?string $selectedId = null;

    #[Locked]
    public string $action = '';

    public bool $showSignatureModal = false;

    public array $form = ['knedp' => '', 'keyContainerUpload' => null, 'keyContainerFileName' => '', 'password' => ''];

    public function mount(LegalEntity $legalEntity, ?Person $person = null): void
    {
        $this->legalEntityId = $legalEntity->id;
        $this->personId = $person?->exists ? $person->id : null;
        $this->legalEntityOptions = Repository::legalEntity()->getLegalEntitiesList();
        $this->patientContext = $this->personId !== null;

        if ($person?->exists) {
            $this->loadDrafts();
            $this->search();
        }
    }

    #[Computed]
    public function legalEntity(): LegalEntity
    {
        return Repository::legalEntity()->getById($this->legalEntityId);
    }

    #[Computed]
    public function person(): ?Person
    {
        return $this->personId === null ? null : Repository::person()->getById($this->personId);
    }

    public function searchPatients(): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }
        if (Auth::user()->cannot('viewAny', Person::class)) {
            Session::flash('error', __('device-requests.policy.search_patient'));

            return;
        }

        if ($this->patientContext) {
            Session::flash('error', __('device-requests.policy.patient_context'));

            return;
        }
        $this->validate(['patientSearch' => 'required|string|min:3|max:100']);
        $this->patients = Repository::person()->searchBySurname(trim($this->patientSearch));
    }

    public function openPatient(int $id): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }
        if (Auth::user()->cannot('viewAny', Person::class)) {
            Session::flash('error', __('device-requests.policy.search_patient'));

            return;
        }

        if ($this->patientContext) {
            Session::flash('error', __('device-requests.policy.patient_context'));

            return;
        }
        $person = Repository::person()->getById($id);
        abort_unless(Str::isUuid($person->uuid), 404);
        $this->personId = $person->id;
        unset($this->person);
        $this->patients = [];
        $this->resetFilters();
        $this->loadDrafts();
    }

    public function clearPatient(): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }

        if ($this->patientContext) {
            Session::flash('error', __('device-requests.policy.patient_context'));

            return;
        }
        $this->personId = null;
        unset($this->person);
        $this->filters->reset();
        $this->reset('drafts', 'requests', 'page', 'totalPages', 'patients', 'requesterEntitySearch');
        $this->clearDetails();
    }

    private function loadDrafts(): void
    {
        $this->drafts = [];
        if (Auth::user()->can('create', DeviceRequestRequest::class)) {
            app(DeviceRequestLifecycleService::class)->authorizeAction($this->legalEntity, 'device_request:write');
            $this->drafts = MedicalEventsRepository::deviceRequest()->draftsForPatientUser($this->personId, Auth::user(), $this->legalEntity)
                ->map(fn (DeviceRequestRequest $draft): array => [
                    'uuid' => $draft->uuid, 'device' => $draft->deviceId, 'updatedAt' => $draft->updatedAt->format('d.m.Y H:i'),
                ])->all();
        }
    }

    public function resetFilters(): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }

        $this->filters->reset();
        $this->reset('requesterEntitySearch');
        $this->search();
    }

    protected function clearDetails(): void
    {
        $this->reset(
            'selected',
            'selectedId',
            'catalog',
            'dispenses',
            'dispenseDetails',
            'dispensesLoaded',
            'action',
            'reasons',
            'reason',
            'reasonText',
            'phone',
            'phoneConfirmed',
            'showSignatureModal',
            'form'
        );
        $this->resetValidation();
    }

    public function search(bool $resetPage = true): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }

        abort_unless($this->person?->exists, 404);
        $this->validate(['requesterEntitySearch' => 'nullable|string|max:500']);
        $entitySearch = trim($this->requesterEntitySearch);
        if ($entitySearch === '' || Str::isUuid($entitySearch)) {
            $this->filters->parameters['requester_legal_entity'] = $entitySearch;
        } else {
            $matches = collect($this->legalEntityOptions)->filter(
                fn (array $entity): bool => mb_strtolower($entity['name']) === mb_strtolower($entitySearch)
            );
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages([
                    'requesterEntitySearch' => __('device-requests.labels.choose_entity_from_list'),
                ]);
            }
            $this->filters->parameters['requester_legal_entity'] = $matches->first()['uuid'];
        }
        $filters = $this->filters->toQuery();

        if (Auth::user()->cannot('viewAllForPatient', [DeviceRequestRequest::class, $this->person])) {
            $requesterEntity = $filters['requester_legal_entity'] ?? '';
            if ($requesterEntity !== '' && $requesterEntity !== $this->legalEntity->uuid) {
                $message = __('device-requests.policy.patient_access_required');
                $this->addError('requesterEntitySearch', $message);
                $this->addError('filters.parameters.requester_legal_entity', $message);
                Session::flash('error', $message);

                return;
            }

            $filters['requester_legal_entity'] = $this->legalEntity->uuid;
            $this->filters->parameters['requester_legal_entity'] = $this->legalEntity->uuid;
            $this->requesterEntitySearch = $this->legalEntity->uuid;
        }

        if ($resetPage) {
            $this->page = 1;
            $this->clearDetails();
        }

        try {
            $response = EHealth::deviceRequest()->getBySearchParams($this->person->uuid, $filters + ['page' => $this->page]);
            $this->requests = $response->validate();
            $this->totalPages = (int) ($response->getPaging()['total_pages'] ?? 1);
        } catch (Throwable $exception) {
            $this->requests = [];
            $this->error($exception);
        }
    }

    public function goToPage(int $page): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }

        $this->page = max(1, min($this->totalPages, $page));
        $this->search(false);
    }

    public function showDetails(string $id, bool $loadCatalog = true): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }

        $service = app(DeviceRequestLifecycleService::class);
        abort_unless($this->person?->exists, 404);

        $this->clearDetails();

        try {
            abort_unless(Str::isUuid($id), 404);
            $response = EHealth::deviceRequest()->getById($this->person->uuid, $id);
            $this->selected = $response->validate();
            $this->selectedId = $id;
            $this->catalog = [];
            $this->phoneConfirmed = false;
            $this->phone = data_get($response->getUrgent(), 'authentication_method_current.phone_number', '');
            $modelId = data_get($this->selected, 'code_reference.identifier.value');

            if ($modelId && $loadCatalog) {
                $this->catalog = [EHealth::deviceDefinition()->getById($modelId)->validate()];
            } else {
                foreach ($this->selected['code']['coding'] ?? [] as $coding) {
                    $labels = $service->dictionary($coding['system']);
                    $models = $loadCatalog ? $service->collectPages(fn (int $page) => EHealth::deviceDefinition()->getMany([
                        'classification_type_system' => $coding['system'], 'classification_type_code' => $coding['code'], 'is_active' => true, 'page' => $page,
                    ])) : [];

                    foreach ($models as $model) {
                        $this->catalog[] = $model;
                    }

                    foreach ($this->selected['code']['coding'] as &$selectedCoding) {
                        if ($selectedCoding['system'] === $coding['system'] && $selectedCoding['code'] === $coding['code']) {
                            $selectedCoding['display'] = $labels[$coding['code']] ?? $coding['code'];
                        }
                    }
                    unset($selectedCoding);
                }
            }
        } catch (Throwable $exception) {
            $this->selected = [];
            $this->selectedId = null;
            $this->error($exception);
        }
    }

    public function startAction(string $action): void
    {
        abort_unless($this->selectedId !== null && $this->person?->exists && in_array($action, ['revoke', 'mark_in_error'], true), 404);
        $service = app(DeviceRequestLifecycleService::class);
        if (!$this->canPerform('device_request:'.$action)) {
            return;
        }

        try {
            $service->assertTransition($service->details($this->person, $this->selectedId), $action);
            $this->action = $action;
            $this->reason = $this->reasonText = '';
            $this->reasons = $service->dictionary($action === 'revoke' ? 'device_request_revoke_reasons' : 'device_request_mark_in_error_reasons');
        } catch (Throwable $exception) {
            $this->error($exception);
        }
    }

    public function openSignatureModal(): void
    {
        try {
            app(DeviceRequestLifecycleService::class)->actionContent($this->person, $this->legalEntity, $this->selectedId, $this->action, $this->reason, $this->reasonText);
            $this->showSignatureModal = true;
        } catch (Throwable $exception) {
            $this->error($exception);
        }
    }

    public function sign(): void
    {
        $this->validate(['form.knedp' => 'required|string', 'form.keyContainerUpload' => 'required|file|max:1024|extensions:dat,pfx,zs2,jks', 'form.password' => 'required|string']);

        try {
            $service = app(DeviceRequestLifecycleService::class);
            $content = $service->actionContent($this->person, $this->legalEntity, $this->selectedId, $this->action, $this->reason, $this->reasonText);
            $signedData = signatureService()->signData($content, $this->form['password'], $this->form['knedp'], $this->form['keyContainerUpload'], (string) Auth::user()->party->taxId);
            $this->selected = $service->submitAction($this->person, $this->legalEntity, $this->selectedId, $this->action, $signedData);
            $this->action = '';
            Session::flash('success', __('device-requests.messages.status_changed'));
            $this->search(false);
        } catch (Throwable $exception) {
            $this->error($exception);
        } finally {
            $this->showSignatureModal = false;
            $this->form['password'] = '';
            $this->form['keyContainerUpload'] = null;
            $this->form['keyContainerFileName'] = '';
        }
    }

    public function complete(): void
    {
        try {
            $this->selected = app(DeviceRequestLifecycleService::class)->submitAction($this->person, $this->legalEntity, $this->selectedId, 'complete');
            Session::flash('success', __('device-requests.messages.completed'));
            $this->search(false);
        } catch (Throwable $exception) {
            $this->error($exception);
        }
    }

    public function resendSms(): void
    {
        try {
            app(DeviceRequestLifecycleService::class)->resendOnce($this->person, $this->legalEntity, $this->selectedId, $this->phoneConfirmed, $this->phone);
            Session::flash('success', __('device-requests.messages.sms_resent'));
            $this->phoneConfirmed = false;
        } catch (Throwable $exception) {
            $this->error($exception);
        }
    }

    public function loadDispenses(): void
    {
        if (!$this->canPerform('device_dispense:read')) {
            return;
        }

        $service = app(DeviceRequestLifecycleService::class);
        abort_unless($this->person?->exists, 404);
        $this->validate(['selectedId' => 'required|uuid']);
        $this->resetValidation();
        $this->dispenses = $this->dispenseDetails = [];
        $this->dispensesLoaded = false;

        try {
            $this->dispenses = $service->collectPages(fn (int $page) => EHealth::deviceRequest()->getDispenses($this->person->uuid, ['based_on' => $this->selectedId, 'page' => $page]));
            $this->dispensesLoaded = true;
        } catch (Throwable $exception) {
            $this->error($exception);
        }
    }

    public function showDispense(string $id): void
    {
        if (!$this->canPerform('device_dispense:read')) {
            return;
        }

        abort_unless(Str::isUuid($id), 404);
        $this->validate(['selectedId' => 'required|uuid']);
        abort_unless($this->person?->exists, 404);
        $this->dispenseDetails = [];
        $this->resetValidation();

        try {
            $record = EHealth::deviceRequest()->getDispense($this->person->uuid, $id)->validate();
            abort_unless(data_get($record, 'based_on.identifier.value') === $this->selectedId || collect($record['based_on'] ?? [])->contains(fn ($reference) => data_get($reference, 'identifier.value') === $this->selectedId), 404);
            $this->dispenseDetails = $record;
        } catch (Throwable $exception) {
            $this->error($exception);
        }
    }

    public function printout(): void
    {
        if (!$this->canPerform('device_request:read')) {
            return;
        }

        abort_unless($this->person?->exists, 404);
        $this->validate(['selectedId' => 'required|uuid']);
        $this->resetValidation();

        try {
            $html = app(DeviceRequestLifecycleService::class)->printoutHtml($this->person, $this->selectedId);
            $this->dispatch('print-device-request', html: $html);
        } catch (Throwable $exception) {
            $this->error($exception);
        }
    }

    protected function canPerform(string $scope): bool
    {
        if (Auth::user()->cannot('perform', [DeviceRequestRequest::class, $scope])) {
            Session::flash('error', __('device-requests.policy.perform'));

            return false;
        }

        return true;
    }

    private function error(Throwable $exception): void
    {
        if ($exception instanceof EHealthException || $exception instanceof EHealthConnectionException) {
            $exception->handle('Error while processing a device request action');

            return;
        }

        if ($exception instanceof ValidationException) {
            $this->setErrorBag($exception->validator->errors());
        } else {
            report($exception);
        }
        Session::flash('error', $exception->getMessage());
    }

    public function render()
    {
        return view('livewire.device-request.device-request-index', [
            'legalEntity' => $this->legalEntity,
            'person' => $this->person,
            'legalEntityNames' => collect($this->legalEntityOptions)->pluck('name', 'uuid')->all(),
        ])->layout('layouts.app');
    }
}
