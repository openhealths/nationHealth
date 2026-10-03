<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\MedicalProgram\Type as MedicalProgramType;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Concerns\MedicalEvents\Referral\SelectsReferralApi;
use App\Mapping\EHealth\Referral\ServiceRequestInput;
use App\Mapping\EHealth\Referral\ServiceRequestPayloads;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use App\Services\Dictionary\ServiceSearch;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use RuntimeException;
use Throwable;

trait ManagesEncounterReferrals
{
    use SelectsReferralApi;
    // Uses ResolvesEncounterStandaloneContext via EncounterEdit.

    public bool $showEncounterReferralDrawer = false;

    /** @var array<string, mixed> */
    public array $encounterReferralForm = [];

    /** @var list<array{uuid: string, type: string, label: string, raw: string}> */
    public array $encounterReferralAuthMethods = [];

    #[Locked]
    public ?string $encounterReferralRequestIdToSign = null;

    public string $encounterReferralWarningMessage = '';

    public string $encounterReferralServiceSearch = '';

    public bool $encounterReferralHasSearched = false;

    /** @var list<array<string, mixed>> */
    public array $encounterReferralServiceResults = [];

    /** @var array<string, mixed>|null */
    public ?array $encounterReferralSelectedService = null;

    /** @var list<array{id: string, name: string}> */
    public array $encounterReferralPrograms = [];

    public function openEncounterReferralDrawer(): void
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }

        $status = $encounter->status instanceof EncounterStatus
            ? $encounter->status
            : EncounterStatus::tryFrom((string) $encounter->status);

        if ($status !== EncounterStatus::FINISHED) {
            Session::flash('error', __('Виписати направлення можна лише для завершеної взаємодії.'));

            return;
        }

        $this->loadEncounterReferralAuthMethods($encounter);
        $this->loadEncounterReferralPrograms();

        $start = now();
        $this->encounterReferralForm = [
            'category' => 'diagnostic_procedure',
            'service_id' => '',
            'priority' => 'routine',
            'quantity' => 1,
            'started_at' => $start->format('d.m.Y'),
            'ended_at' => $start->copy()->addMonths(3)->format('d.m.Y'),
            'program_id' => $this->resolveDefaultEncounterReferralProgramId(),
            'note' => '',
            'patient_instruction' => '',
            'inform_with' => $this->authenticationMethodFormValue($this->encounterReferralAuthMethods[0] ?? []),
            'reason_reference' => [],
        ];

        $this->encounterReferralServiceSearch = '';
        $this->encounterReferralHasSearched = false;
        $this->encounterReferralServiceResults = [];
        $this->encounterReferralSelectedService = null;
        $this->encounterReferralWarningMessage = '';
        $this->showEncounterReferralDrawer = true;
    }

    public function closeEncounterReferralDrawer(): void
    {
        $this->showEncounterReferralDrawer = false;
        $this->encounterReferralWarningMessage = '';
        $this->encounterReferralServiceResults = [];
        $this->encounterReferralHasSearched = false;
    }

    public function searchEncounterReferralServices(): void
    {
        $query = trim((string) $this->encounterReferralServiceSearch);
        if ($query === '') {
            $this->encounterReferralServiceResults = [];
            $this->encounterReferralHasSearched = false;

            return;
        }
        $this->encounterReferralHasSearched = true;

        try {
            $this->encounterReferralServiceResults = ServiceSearch::search(
                $query,
                static fn (array $params): array => EHealth::service()->getMany($params)->getData()
            );
            $this->encounterReferralWarningMessage = '';
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: service search failed for standalone referral: '.$exception->getMessage());
            $this->encounterReferralServiceResults = [];
            $this->encounterReferralWarningMessage = __('Не вдалося виконати пошук послуг. Спробуйте ще раз.');
            Session::flash('error', $this->encounterReferralWarningMessage);
        }
    }

    public function selectEncounterReferralService(string $serviceId): void
    {
        $selected = collect($this->encounterReferralServiceResults)
            ->first(static fn (array $service): bool => (string) ($service['id'] ?? '') === $serviceId);

        if (!is_array($selected)) {
            $this->encounterReferralWarningMessage = __('Не вдалося обрати послугу. Спробуйте пошукати ще раз.');
            Session::flash('error', $this->encounterReferralWarningMessage);

            return;
        }

        $this->encounterReferralForm['service_id'] = $serviceId;
        $this->encounterReferralSelectedService = $selected;
        $category = ServiceSearch::requestCategory($selected);
        if ($category !== null) {
            $this->encounterReferralForm['category'] = $category;
        }

        // Hide the result list after pick — same UX as standalone eRx (readable in dark mode)
        $this->encounterReferralServiceSearch = '';
        $this->encounterReferralServiceResults = [];
        $this->encounterReferralHasSearched = false;
        $this->encounterReferralWarningMessage = '';
    }

    #[On('encounter-referral-service-selected')]
    public function selectEncounterReferralServiceFromCatalog(array $service): void
    {
        $this->encounterReferralForm['service_id'] = $service['id'];
        $this->encounterReferralSelectedService = $service;

        $category = ServiceSearch::requestCategory($service);
        if ($category !== null) {
            $this->encounterReferralForm['category'] = $category;
        }

        $this->encounterReferralServiceSearch = '';
        $this->encounterReferralServiceResults = [];
        $this->encounterReferralHasSearched = false;
        $this->encounterReferralWarningMessage = '';
        
        $this->dispatch('encounter-referral-service-catalog-close');
    }

    public function validateEncounterReferral(): void
    {
        $this->encounterReferralWarningMessage = '';

        $this->validate([
            'encounterReferralForm.service_id' => 'required|string|uuid',
            'encounterReferralForm.category' => 'required|string',
            'encounterReferralForm.quantity' => 'required|numeric|min:0.01',
            'encounterReferralForm.priority' => 'required|in:routine,urgent,asap,stat',
            'encounterReferralForm.started_at' => 'required|date_format:d.m.Y',
            'encounterReferralForm.ended_at' => 'required|date_format:d.m.Y|after_or_equal:encounterReferralForm.started_at',
        ], [], [
            'encounterReferralForm.service_id' => __('код послуги'),
            'encounterReferralForm.category' => __('категорія'),
            'encounterReferralForm.quantity' => __('кількість'),
            'encounterReferralForm.priority' => __('пріоритет'),
            'encounterReferralForm.started_at' => __('дата початку'),
            'encounterReferralForm.ended_at' => __('дата закінчення'),
        ]);

        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }

        try {
            $employeeContext = app(ReferralRequestLifecycleService::class)->resolveEncounterEmployeeContext(
                $encounter,
                Auth::user()?->activeDoctorEmployee()?->id
            );

            $formData = $this->encounterReferralForm;
            $formData['program_id'] = $formData['program_id'] !== '' ? $formData['program_id'] : null;
            $formData['kind'] = 'service_request';

            $this->encounterReferralRequestIdToSign = app(ReferralRequestLifecycleService::class)->createEncounterDraft(
                $encounter,
                $formData,
                (float) $formData['quantity'],
                $employeeContext
            );

            $this->showEncounterReferralDrawer = false;
            $this->actionType = 'sign_referral';
            Session::flash('success', __('Заявку на електронне направлення створено. Підпишіть КЕП.'));
            $this->showSignatureModal = true;
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->encounterReferralWarningMessage = $exception->getFormattedMessage();
            Session::flash('error', $this->encounterReferralWarningMessage);
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: failed to create encounter referral: '.$exception->getMessage());
            $this->encounterReferralWarningMessage = __('Не вдалося створити направлення: ').$exception->getMessage();
            Session::flash('error', $this->encounterReferralWarningMessage);
        }
    }

    public function signEncounterReferral(): void
    {
        if (empty($this->encounterReferralRequestIdToSign)) {
            Session::flash('error', __('Не вибрано направлення для підписання'));
            $this->showSignatureModal = false;
            $this->actionType = null;

            return;
        }

        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            $this->showSignatureModal = false;
            $this->actionType = null;

            return;
        }

        try {
            $requestRecord = Repository::serviceRequest()->findOwnedForEncounter(
                (string) $this->encounterReferralRequestIdToSign,
                $encounter,
                legalEntity()?->id
            );

            $validated = $this->form->validate($this->form->signingRules());
            $person = Person::find($encounter->person_id);
            if ($person === null || empty($person->uuid)) {
                throw new RuntimeException(__('Пацієнта не знайдено'));
            }

            $lifecycle = app(ReferralRequestLifecycleService::class);
            $employeeContext = $lifecycle->resolveEncounterEmployeeContext(
                $encounter,
                $requestRecord->employeeId ?? Auth::user()?->activeDoctorEmployee()?->id
            );

            $dbData = $lifecycle->buildSignDbData($requestRecord, null, $encounter, $employeeContext);

            $uuids = [
                'person_uuid' => $person->uuid,
                'encounter_uuid' => $encounter->uuid,
                'episode_uuid' => $encounter->episode?->value ?? null,
                'employee_uuid' => $employeeContext['employee_uuid'],
                'legal_entity_uuid' => $employeeContext['legal_entity_uuid'],
            ];

            $signPayload = app(ServiceRequestPayloads::class)->signedCreate(
                ServiceRequestInput::fromArray($dbData, $uuids, CarbonImmutable::now())
            );

            $signedContent = signatureService()->signData(
                $signPayload,
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = $this->referralApi('service_request')->createSignedAndResolve($person->uuid, $signedContent);

            $dbData = $lifecycle->persistAfterSignedCreate(
                $dbData,
                $finalResponse,
                'service_request',
                (int) $encounter->person_id
            );

            if (empty($dbData['request_number']) && !empty($dbData['uuid'])) {
                try {
                    $remote = $lifecycle->fetchRemoteReferral($person->uuid, $dbData['uuid'], 'service_request');
                    $dbData['request_number'] = $remote['requisition'] ?? $remote['request_number'] ?? $dbData['request_number'];
                    if (!empty($dbData['request_number'])) {
                        ServiceRequestRequest::where('uuid', $dbData['uuid'])
                            ->update(['request_number' => $dbData['request_number']]);
                    }
                } catch (Throwable $e) {
                    Log::warning('EncounterEdit: failed to fetch remote referral for number: '.$e->getMessage());
                }
            }

            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->encounterReferralRequestIdToSign = null;
            $this->form->resetSigningFields();

            $referralIdentifier = $dbData['request_number'] ?? $dbData['uuid'];
            Session::flash('success', __('Електронне направлення успішно створено без плану лікування. Номер направлення: :number', ['number' => $referralIdentifier]));
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = null;
        } catch (EHealthValidationException $exception) {
            $exception->report();
            Session::flash('error', $exception->getFormattedMessage());
            $this->showSignatureModal = false;
            $this->actionType = null;
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: failed to sign encounter referral: '.$exception->getMessage());
            Session::flash('error', __('Не вдалося підписати направлення: ').$exception->getMessage());
            $this->showSignatureModal = false;
            $this->actionType = null;
        }
    }

    protected function loadEncounterReferralAuthMethods(Encounter $encounter): void
    {
        $this->encounterReferralAuthMethods = [];
        $person = Person::find($encounter->person_id);
        if ($person === null || empty($person->uuid)) {
            return;
        }

        try {
            $authMethods = EHealth::person()->getAuthMethods($person->uuid)->getData();
            if (!is_array($authMethods)) {
                return;
            }

            $this->encounterReferralAuthMethods = collect($authMethods)->map(static function (array $method): array {
                $uuid = (string) ($method['id'] ?? $method['uuid'] ?? '');
                $type = (string) ($method['type'] ?? '');
                $phone = (string) ($method['phone_number'] ?? $method['value'] ?? '');

                return [
                    'uuid' => $uuid,
                    'type' => $type,
                    'label' => trim($type.($phone !== '' ? ' · '.$phone : '')),
                    'raw' => $uuid !== '' ? "{$uuid}|{$type}|{$phone}" : '',
                ];
            })->filter(static fn (array $m): bool => $m['uuid'] !== '')->values()->all();
        } catch (Throwable $exception) {
            Log::warning('EncounterEdit: failed to load auth methods for referral: '.$exception->getMessage());
        }
    }

    protected function loadEncounterReferralPrograms(): void
    {
        try {
            $this->encounterReferralPrograms = dictionary()->medicalPrograms()
                ->where('is_active', true)
                ->where('type', MedicalProgramType::SERVICE->value)
                ->map(static fn (array $program): array => [
                    'id' => (string) ($program['id'] ?? ''),
                    'name' => (string) ($program['name'] ?? ''),
                ])
                ->filter(static fn (array $program): bool => $program['id'] !== '' && $program['name'] !== '')
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('EncounterEdit: failed to load service programs for standalone referral: '.$exception->getMessage());
            $this->encounterReferralPrograms = [];
        }
    }

    /**
     * Prefer PMG (state guarantees) when present; otherwise first loaded SERVICE program.
     */
    protected function resolveDefaultEncounterReferralProgramId(): string
    {
        foreach ($this->encounterReferralPrograms as $program) {
            $name = mb_strtolower((string) ($program['name'] ?? ''));
            if (
                str_contains($name, 'державних фінансових гарантій')
                || str_contains($name, 'пмг')
            ) {
                return (string) ($program['id'] ?? '');
            }
        }

        return (string) ($this->encounterReferralPrograms[0]['id'] ?? '');
    }

    /**
     * Select option value for Livewire forms. Accepts snapshots that still
     * only have `uuid` from before `raw` was added.
     *
     * @param  array{raw?: string, uuid?: string, type?: string, phone_number?: string}  $method
     */
    protected function authenticationMethodFormValue(array $method): string
    {
        $raw = trim((string) ($method['raw'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        $uuid = trim((string) ($method['uuid'] ?? ''));
        if ($uuid === '') {
            return '';
        }

        $type = (string) ($method['type'] ?? '');
        $phone = (string) ($method['phone_number'] ?? '');

        return ($type !== '' || $phone !== '') ? "{$uuid}|{$type}|{$phone}" : $uuid;
    }
}
