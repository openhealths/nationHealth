<?php

declare(strict_types=1);

namespace App\Livewire\DeviceRequest;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\LegalEntityRepository;
use App\Repositories\PersonRepository;
use App\Repositories\MedicalEvents\DeviceRequestRequestRepository;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Livewire\DeviceRequest\Forms\DeviceRequestSearchForm;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Computed;
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
        $this->patientContext = $this->personId !== null;
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $legalEntity, 'device_request:read']), 404);

        if ($person?->exists) {
            $this->loadDrafts();
            $this->search();
        }
    }

    #[Computed]
    public function legalEntity(): LegalEntity
    {
        return app(LegalEntityRepository::class)->getById($this->legalEntityId);
    }

    #[Computed]
    public function person(): ?Person
    {
        return $this->personId === null ? null : app(PersonRepository::class)->getById($this->personId);
    }

    public function searchPatients(): void
    {
        abort_if($this->patientContext, 404);
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:read']), 404);
        abort_unless(Auth::user()->can('viewAny', Person::class), 404);
        $this->validate(['patientSearch' => 'required|string|min:3|max:100']);
        $this->patients = app(PersonRepository::class)->searchBySurname(trim($this->patientSearch));
    }

    public function openPatient(int $id): void
    {
        abort_if($this->patientContext, 404);
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:read']), 404);
        abort_unless(Auth::user()->can('viewAny', Person::class), 404);
        $person = app(PersonRepository::class)->getById($id);
        abort_unless(\Illuminate\Support\Str::isUuid($person->uuid), 404);
        $this->personId = $person->id;
        unset($this->person);
        $this->patients = [];
        $this->resetFilters();
        $this->loadDrafts();
    }

    public function clearPatient(): void
    {
        abort_if($this->patientContext, 404);
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:read']), 404);
        $this->personId = null;
        unset($this->person);
        $this->filters->reset();
        $this->reset('drafts', 'requests', 'page', 'totalPages', 'patients');
        $this->clearDetails();
    }

    private function loadDrafts(): void
    {
        $this->drafts = [];
        if (Auth::user()->can('create', DeviceRequestRequest::class)) {
            $employee = app(DeviceRequestLifecycleService::class)->authorizeAction($this->legalEntity, 'device_request:write');
            $this->drafts = app(DeviceRequestRequestRepository::class)->draftsForPatientEmployee($this->personId, $employee->id);
        }
    }

    public function resetFilters(): void
    {
        $this->filters->reset();
        $this->search();
    }

    private function clearDetails(): void
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
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:read']), 404);
        abort_unless($this->person?->exists, 404);
        $filters = $this->filters->toQuery();

        if ($resetPage) {
            $this->page = 1;
            $this->clearDetails();
        }

        try {
            $response = EHealth::deviceRequest()->getBySearchParams($this->person->uuid, $filters + ['page' => $this->page]);
            $this->requests = $response->getData();
            $this->totalPages = (int) ($response->getPaging()['total_pages'] ?? 1);
        } catch (Throwable $exception) {
            $this->requests = [];
            $this->error($exception);
        }
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($this->totalPages, $page));
        $this->search(false);
    }

    public function showDetails(string $id): void
    {
        $service = app(DeviceRequestLifecycleService::class);
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:read']), 404);
        abort_unless($this->person?->exists, 404);

        $this->clearDetails();

        try {
            abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
            $response = EHealth::deviceRequest()->getById($this->person->uuid, $id);
            $this->selected = $response->getData();
            $this->selectedId = $id;
            $this->catalog = [];
            $this->phoneConfirmed = false;
            $this->phone = data_get($response->getUrgent(), 'authentication_method_current.phone_number', '');
            $modelId = data_get($this->selected, 'code_reference.identifier.value');

            if ($modelId) {
                $this->catalog = [EHealth::deviceDefinition()->getById($modelId)->getData()];
            } else {
                foreach ($this->selected['code']['coding'] ?? [] as $coding) {
                    $labels = $service->dictionary($coding['system']);
                    $models = $service->collectPages(fn (int $page) => EHealth::deviceDefinition()->getMany([
                        'classification_type_system' => $coding['system'], 'classification_type_code' => $coding['code'], 'is_active' => true, 'page' => $page,
                    ]));

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
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:'.$action]), 404);

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
        $service = app(DeviceRequestLifecycleService::class);
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_dispense:read']), 404);
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
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_dispense:read']), 404);
        $this->validate(['selectedId' => 'required|uuid']);
        abort_unless($this->person?->exists, 404);
        $this->dispenseDetails = [];
        $this->resetValidation();

        try {
            $record = EHealth::deviceRequest()->getDispense($this->person->uuid, $id)->getData();
            abort_unless(data_get($record, 'based_on.identifier.value') === $this->selectedId || collect($record['based_on'] ?? [])->contains(fn ($reference) => data_get($reference, 'identifier.value') === $this->selectedId), 404);
            $this->dispenseDetails = $record;
        } catch (Throwable $exception) {
            $this->error($exception);
        }
    }

    public function printout(): void
    {
        abort_unless(Auth::user()->can('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:read']), 404);
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

    private function error(Throwable $exception): void
    {
        if ($exception instanceof EHealthException || $exception instanceof EHealthConnectionException) {
            $exception->handle('Error while processing a device request action');

            return;
        }

        if ($exception instanceof \Illuminate\Validation\ValidationException) {
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
        ])->layout('layouts.app');
    }
}
