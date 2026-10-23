<?php

declare(strict_types=1);

namespace App\Livewire\DeviceRequest;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\LegalEntityRepository;
use App\Repositories\PersonRepository;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\MedicalEvents\EHealthJobResolver;
use App\Services\Dictionary\DictionaryManager;
use App\Repositories\MedicalEvents\DeviceRequestRequestRepository;
use App\Exceptions\EHealth\EHealthValidationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class DeviceRequestForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $legalEntityId;

    #[Locked]
    public int $personId;

    #[Locked]
    public ?string $carePlanUuid = null;

    #[Locked]
    public ?string $activityUuid = null;

    #[Locked]
    public ?string $draftId = null;

    #[Locked]
    public string $requestUuid;

    public array $request = [];

    #[Locked]
    public array $programs = [];

    #[Locked]
    public array $types = [];

    #[Locked]
    public array $models = [];

    #[Locked]
    public array $options = [];

    #[Locked]
    public array $authMethods = [];

    #[Locked]
    public array $encounters = [];

    #[Locked]
    public array $quantityOptions = [];

    #[Locked]
    public array $reasonOptions = [];

    #[Locked]
    public array $units = [];

    #[Locked]
    public array $measurementUnits = [];

    public string $modelSearch = '';

    #[Locked]
    public int $quantityWindow = 100;

    public bool $showSignatureModal = false;

    public array $form = ['knedp' => '', 'keyContainerUpload' => null, 'keyContainerFileName' => '', 'password' => ''];

    public function mount(LegalEntity $legalEntity, Person $person): void
    {
        $this->legalEntityId = $legalEntity->id;
        $this->personId = $person->id;
        $this->requestUuid = (string) Str::uuid();
        $service = app(DeviceRequestLifecycleService::class);
        $employee = $service->authorizeAction($legalEntity, 'device_request:write');
        $this->request = [
            'uuid' => $this->requestUuid, 'encounter_uuid' => '', 'device_id' => '',
            'device_code_type' => 'CLASSIFICATION_TYPE', 'device_code_system' => '', 'program_id' => '',
            'quantity' => null, 'quantity_code' => 'piece', 'started_at' => today()->format('d.m.Y'),
            'ended_at' => today()->addDay()->format('d.m.Y'), 'inform_with' => '', 'phone_confirmed' => false,
            'confirmed_phone' => '', 'reason_reference' => [], 'parameter_values' => [],
        ];
        $this->encounters = $person->encounters()->with(['period', 'performer'])->whereStatus('finished')->get()
            ->filter(fn ($encounter): bool => $encounter->performer?->value === $employee->uuid
                && $encounter->period?->end && CarbonImmutable::parse($encounter->period->end)->isToday())
            ->map(fn ($encounter): array => ['id' => $encounter->id, 'uuid' => $encounter->uuid, 'label' => $encounter->period->end.' · №'.$encounter->id])->values()->all();
        $this->request['encounter_uuid'] = $this->encounters[0]['uuid'] ?? '';

        try {
            $this->reasonOptions = app(DeviceRequestRequestRepository::class)->reasonOptions($person);
            $basics = app(DictionaryManager::class)->basics();
            $this->units = $basics->byName('device_unit')->asCodeDescription()->all();
            $this->measurementUnits = $basics->contains('name', 'eHealth/ucum/units')
                ? $basics->byName('eHealth/ucum/units')->asCodeDescription()->all() : [];

            if (request()->filled('encounter')) {
                $this->request['encounter_uuid'] = '';
                $validated = Validator::make(['encounter' => request()->query('encounter')], ['encounter' => 'required|integer|min:1'])->validate();
                $encounter = collect($this->encounters)->firstWhere('id', (int) $validated['encounter']);

                if ($encounter === null) {
                    throw ValidationException::withMessages(['encounter' => __('device-requests.messages.encounter_unavailable')]);
                }

                $encounterUuid = $encounter['uuid'];
                $service->encounter($person, $employee, $encounterUuid);
                $this->request['encounter_uuid'] = $encounterUuid;
            }

            $this->programs = app(DictionaryManager::class)->medicalPrograms()
                ->where('type', 'DEVICE')->where('is_active', true)->values()->all();
            $this->authMethods = $service->authMethods($person);
            $default = collect($this->authMethods)->firstWhere('type', 'OTP') ?? ($this->authMethods[0] ?? []);
            $this->request['inform_with'] = $default['id'] ?? '';

            if (request()->filled('draft')) {
                $record = DeviceRequestRequest::whereUuid(request()->string('draft')->toString())->wherePersonId($person->id)->whereEmployeeId($employee->id)->firstOrFail();
                abort_unless($record->status === 'draft', 409);

                if (!$record->requestPayload) {
                    throw ValidationException::withMessages(['draft' => __('device-requests.validation.legacy_draft')]);
                }

                $saved = $record->requestPayload;
                $this->request = $saved['data'];

                foreach (['started_at', 'ended_at'] as $dateField) {
                    $this->request[$dateField] = CarbonImmutable::parse($this->request[$dateField])->format('d.m.Y');
                }

                $this->draftId = $this->requestUuid = $record->uuid;
                $this->carePlanUuid = $saved['carePlanUuid'];
                $this->activityUuid = $saved['activityUuid'];
                $this->request['phone_confirmed'] = false;
                $this->request['confirmed_phone'] = '';
            }
            if (request()->filled('carePlan') && request()->filled('activity')) {
                $plan = CarePlan::wherePersonId($person->id)->whereUuid(request()->string('carePlan')->toString())->firstOrFail();
                $activity = $plan->activities()->whereUuid(request()->string('activity')->toString())->firstOrFail();
                $this->carePlanUuid = $plan->uuid;
                $this->activityUuid = $activity->uuid;
                $detail = $service->carePlanContext($person, $this->carePlanUuid, $this->activityUuid)['detail'];
                $this->request['program_id'] = data_get($detail, 'program.identifier.value', '');
                $modelId = data_get($detail, 'product_reference.identifier.value');
                $this->request['device_code_type'] = $modelId ? 'DEVICE_DEFINITION' : 'CLASSIFICATION_TYPE';
                $this->request['device_id'] = $modelId ?: data_get($detail, 'product_codeable_concept.coding.0.code', '');
                $this->request['device_code_system'] = data_get($detail, 'product_codeable_concept.coding.0.system', '');
                $this->request['quantity_code'] = strtolower(data_get($detail, 'quantity.code', 'piece'));

                if (!empty($detail['scheduled_period']['end'])) {
                    $this->request['ended_at'] = CarbonImmutable::parse($detail['scheduled_period']['end'])->format('d.m.Y');
                }

                $this->request['reason_reference'] = collect($detail['reason_reference'] ?? $detail['reason_references'] ?? [])
                    ->map(fn (array $reference): array => ['uuid' => data_get($reference, 'identifier.value'), 'type' => data_get($reference, 'identifier.type.coding.0.code')])->all();
            }

            $this->types = $service->permittedTypes(!empty($this->request['program_id']));

            if ($this->request['device_id'] !== '') {
                $this->refreshOptions();
            }
        } catch (Throwable $exception) {
            $this->reportError($exception);
        }
    }

    #[Computed]
    public function legalEntity(): LegalEntity
    {
        return app(LegalEntityRepository::class)->getById($this->legalEntityId);
    }

    #[Computed]
    public function person(): Person
    {
        return app(PersonRepository::class)->getById($this->personId);
    }

    public function updatedRequest(mixed $value, string $key): void
    {
        $this->showSignatureModal = false;
        $this->resetErrorBag('request.'.$key);

        if (preg_match('/^reason_reference\.(\d+)\.type$/D', $key, $matches)) {
            $this->request['reason_reference'][(int) $matches[1]]['uuid'] = '';
        }
        if ($key === 'inform_with') {
            $this->request['phone_confirmed'] = false;
            $this->request['confirmed_phone'] = '';
        }
        if (in_array($key, ['program_id', 'device_code_type'], true) && $this->activityUuid === null) {
            $this->request['device_id'] = $this->request['device_code_system'] = '';
            $this->request['quantity'] = null;
            $this->request['parameter_values'] = [];
            $this->options = $this->models = $this->quantityOptions = [];
            $this->types = app(DeviceRequestLifecycleService::class)->permittedTypes(!empty($this->request['program_id']));
        }
        if (in_array($key, ['started_at', 'ended_at'], true) && $this->request['device_id'] !== '') {
            $this->refreshOptions();
        }
    }

    public function selectType(string $key): void
    {
        abort_unless($this->activityUuid === null, 403);

        if ($key === '') {
            $this->clearDevice();

            return;
        }

        $types = app(DeviceRequestLifecycleService::class)->permittedTypes(!empty($this->request['program_id']));
        abort_unless(isset($types[$key]), 422);
        $this->request['device_id'] = $types[$key]['code'];
        $this->request['device_code_system'] = $types[$key]['system'];
        $this->request['parameter_values'] = [];
        $this->request['quantity'] = null;
        $this->resetErrorBag('request.device_id');
        $this->refreshOptions();
    }

    private function clearDevice(): void
    {
        $this->request['device_id'] = $this->request['device_code_system'] = '';
        $this->request['quantity'] = null;
        $this->request['parameter_values'] = [];
        $this->options = $this->quantityOptions = [];
    }

    public function searchModels(): void
    {
        $this->authorize('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:write']);

        try {
            $this->modelSearch = trim($this->modelSearch);
            $this->validate(['modelSearch' => 'required|string|min:2'], [], ['modelSearch' => __('device-requests.form.model_name')]);
            $this->models = app(DeviceRequestLifecycleService::class)->collectPages(fn (int $page) => EHealth::deviceDefinition()->getMany([
                'name' => $this->modelSearch, 'medical_program_id' => $this->request['program_id'] ?: null, 'is_active' => true, 'page' => $page,
            ]));
        } catch (Throwable $exception) {
            $this->reportError($exception);
        }
    }

    public function selectModel(string $id): void
    {
        if ($id === '' && $this->activityUuid === null) {
            $this->clearDevice();

            return;
        }
        abort_unless($this->activityUuid === null && Str::isUuid($id), 403);
        $this->request['device_id'] = $id;
        $this->request['device_code_type'] = 'DEVICE_DEFINITION';
        $this->request['parameter_values'] = [];
        $this->request['quantity'] = null;
        $this->resetErrorBag('request.device_id');
        $this->refreshOptions();
    }

    public function refreshOptions(): void
    {
        $service = app(DeviceRequestLifecycleService::class);
        $this->authorize('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:write']);

        try {
            $this->options = $service->prescribingOptions($this->request);
            $this->quantityOptions = [];
            $period = $service->occurrenceDates($this->request);
            $days = $period['start']->diffInDays($period['end']);
            $daily = data_get($this->options, 'program.medical_program_settings.max_daily_count', data_get($this->options, 'program.settings.max_daily_count'));
            $cap = $daily ? (float) $daily * $days : null;

            if ($this->carePlanUuid !== null) {
                $context = $service->carePlanContext($this->person, $this->carePlanUuid, $this->activityUuid);
                $remaining = data_get($context, 'detail.remaining_quantity.value', data_get($context, 'detail.remaining_quantity'));

                if (is_numeric($remaining)) {
                    $cap = $cap === null ? (float) $remaining : min($cap, (float) $remaining);
                }
            }
            foreach ($this->options['packages'] as $package) {
                $packageCap = isset($package['maxDailyCount'])
                    ? $package['maxDailyCount'] * $days
                    : null;

                for ($count = 1; $count <= $this->quantityWindow; $count++) {
                    $quantity = $count * $package['count'];

                    if ($cap !== null && $quantity > $cap) {
                        break;
                    }
                    if ($packageCap !== null && $quantity > $packageCap) {
                        break;
                    }

                    $this->quantityOptions[$package['unit'].'|'.$quantity] = ['quantity' => $quantity, 'unit' => $package['unit']];
                }
            }
        } catch (Throwable $exception) {
            $this->options = $this->quantityOptions = [];
            $this->reportError($exception);
        }
    }

    public function moreQuantities(): void
    {
        $this->quantityWindow += 100;
        $this->refreshOptions();
    }

    public function selectQuantity(string $key): void
    {
        if ($key === '') {
            $this->request['quantity'] = null;

            return;
        }

        $this->refreshOptions();
        abort_unless(isset($this->quantityOptions[$key]), 422);
        $this->request['quantity'] = $this->quantityOptions[$key]['quantity'];
        $this->request['quantity_code'] = $this->quantityOptions[$key]['unit'];
    }

    public function confirmPhone(): void
    {
        $service = app(DeviceRequestLifecycleService::class);
        $this->authorize('perform', [DeviceRequestRequest::class, $this->legalEntity, 'device_request:write']);
        $methods = $service->authMethods($this->person);
        $method = collect($methods)->firstWhere('id', $this->request['inform_with']);

        if (empty($this->request['inform_with'])) {
            $method = collect($methods)->firstWhere('type', 'OTP') ?? ($methods[0] ?? []);
        }

        $this->request['phone_confirmed'] = true;
        $this->request['confirmed_phone'] = $method['phone_number'] ?? '';
    }

    public function addReason(): void
    {
        $this->request['reason_reference'][] = ['type' => 'condition', 'uuid' => ''];
    }

    public function removeReason(int $index): void
    {
        unset($this->request['reason_reference'][$index]);
        $this->request['reason_reference'] = array_values($this->request['reason_reference']);
        $this->resetErrorBag();
    }

    public function createDraft(): bool
    {
        $this->resetErrorBag();
        $service = app(DeviceRequestLifecycleService::class);

        try {
            $this->request['uuid'] = $this->requestUuid;
            $prepared = $service->prepare($this->person, $this->legalEntity, $this->request, $this->carePlanUuid, $this->activityUuid);

            if ($this->draftId !== null) {
                abort_unless($this->ownedDraft()->status === 'draft', 409);
            }

            $record = DB::transaction(function () use ($prepared): DeviceRequestRequest {
                $record = DeviceRequestRequest::updateOrCreate(['uuid' => $this->requestUuid, 'person_id' => $this->person->id, 'employee_id' => $prepared['employee']->id], [
                    'employeeId' => $prepared['employee']->id, 'deviceId' => $this->request['device_id'], 'status' => 'draft',
                    'quantity' => $prepared['data']['quantity'], 'programId' => $this->request['program_id'] ?: null,
                    'requestPayload' => ['data' => $prepared['data'], 'carePlanUuid' => $this->carePlanUuid, 'activityUuid' => $this->activityUuid],
                ]);

                app(DeviceRequestRequestRepository::class)->store($prepared['data'] + [
                    'employee_id' => $prepared['employee']->id, 'status' => 'draft',
                    'based_on_uuid' => $this->activityUuid, 'context_uuid' => $this->request['encounter_uuid'],
                ], (int) $this->person->id);

                return $record;
            });
            $this->draftId = $record->uuid;
            Session::flash('success', __('device-requests.messages.draft_saved'));

            return true;
        } catch (Throwable $exception) {
            $this->refreshOptions();
            $this->reportError($exception);

            return false;
        }
    }

    private function ownedDraft(): DeviceRequestRequest
    {
        $employee = app(DeviceRequestLifecycleService::class)->authorizeAction($this->legalEntity, 'device_request:write');

        return DeviceRequestRequest::whereUuid($this->draftId)->wherePersonId($this->person->id)->whereEmployeeId($employee->id)->firstOrFail();
    }

    public function openSignatureModal(): void
    {
        if ($this->createDraft() && $this->draftId !== null) {
            $this->showSignatureModal = $this->ownedDraft()->status === 'draft';
        }
    }

    public function sign(): void
    {
        $this->validate(['form.knedp' => 'required|string', 'form.keyContainerUpload' => 'required|file|max:1024|extensions:dat,pfx,zs2,jks', 'form.password' => 'required|string']);
        $lock = Cache::lock('device-request-sign:'.$this->person->uuid.':'.($this->activityUuid ?? $this->requestUuid), 600);

        try {
            if (!$lock->get()) {
                throw ValidationException::withMessages(['sign' => __('device-requests.validation.concurrent_prescribing')]);
            }

            $record = $this->ownedDraft();
            abort_unless($record->status === 'draft', 409);
            $saved = $record->requestPayload;
            abort_unless(($saved['carePlanUuid'] ?? null) === $this->carePlanUuid
                && ($saved['activityUuid'] ?? null) === $this->activityUuid, 409);
            $service = app(DeviceRequestLifecycleService::class);
            $prepared = $service->prepare($this->person, $this->legalEntity, $saved['data'], $saved['carePlanUuid'], $saved['activityUuid']);
            $signedData = signatureService()->signData($prepared['payload'], $this->form['password'], $this->form['knedp'], $this->form['keyContainerUpload'], (string) Auth::user()->party->taxId);
            $response = EHealth::deviceRequest()->createSigned($this->person->uuid, ['signed_data' => $signedData, 'signed_data_encoding' => 'base64']);
            app(EHealthJobResolver::class)->resolve($response->getData());
            $remote = $service->details($this->person, $record->uuid);

            if (($remote['status'] ?? '') !== 'active' || empty($remote['requisition'])) {
                throw ValidationException::withMessages(['status' => __('device-requests.validation.creation_unconfirmed')]);
            }

            $service->sync($this->person, $remote);
            Session::flash('success', $service->createdMessage($remote['requisition'], !empty($saved['data']['program_id']), $prepared['authType']));
            $this->redirectRoute('persons.device-requests', ['legalEntity' => $this->legalEntity, 'person' => $this->person]);
        } catch (Throwable $exception) {
            $this->refreshOptions();
            $this->reportError($exception);
        } finally {
            $lock->release();
            $this->showSignatureModal = false;
            $this->form['password'] = '';
            $this->form['keyContainerUpload'] = null;
            $this->form['keyContainerFileName'] = '';
        }
    }

    private function reportError(Throwable $exception): void
    {
        if ($exception instanceof ValidationException) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $field = $field === 'encounter' ? 'encounter_uuid' : $field;
                $errors[str_starts_with($field, 'form.') || $field === 'modelSearch' ? $field : 'request.'.$field] = $messages;
            }

            $this->setErrorBag($errors);
            Session::flash('error', $exception->validator->errors()->first());
        } else {
            report($exception);
            Session::flash('error', $exception instanceof EHealthValidationException ? $exception->getFormattedMessage() : $exception->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.device-request.device-request-form', [
            'legalEntity' => $this->legalEntity,
            'person' => $this->person,
        ])->layout('layouts.app');
    }
}
