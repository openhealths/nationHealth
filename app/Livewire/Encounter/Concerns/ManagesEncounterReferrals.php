<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\MedicalProgram\Type as MedicalProgramType;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Services\Dictionary\ServiceSearch;
use App\Services\MedicalEvents\InformWith;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;

trait ManagesEncounterReferrals
{
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
            Session::flash('error', 'Виписати направлення можна лише для завершеної взаємодії.');

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
            'program_id' => '',
            'note' => '',
            'patient_instruction' => '',
            'inform_with' => InformWith::formValue($this->encounterReferralAuthMethods[0] ?? []),
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
        } catch (\Throwable $exception) {
            Log::error('EncounterEdit: service search failed for standalone referral: '.$exception->getMessage());
            $this->encounterReferralServiceResults = [];
            $this->encounterReferralWarningMessage = 'Не вдалося виконати пошук послуг. Спробуйте ще раз.';
            Session::flash('error', $this->encounterReferralWarningMessage);
        }
    }

    public function selectEncounterReferralService(string $serviceId): void
    {
        $selected = collect($this->encounterReferralServiceResults)
            ->first(static fn (array $service): bool => (string) ($service['id'] ?? '') === $serviceId);

        if (!is_array($selected)) {
            $this->encounterReferralWarningMessage = 'Не вдалося обрати послугу. Спробуйте пошукати ще раз.';
            Session::flash('error', $this->encounterReferralWarningMessage);

            return;
        }

        $this->encounterReferralForm['service_id'] = $serviceId;
        $this->encounterReferralSelectedService = $selected;
        $category = ServiceSearch::requestCategory($selected);
        if ($category !== null) {
            $this->encounterReferralForm['category'] = $category;
        }
        $this->encounterReferralWarningMessage = '';
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
            'encounterReferralForm.service_id' => 'код послуги',
            'encounterReferralForm.category' => 'категорія',
            'encounterReferralForm.quantity' => 'кількість',
            'encounterReferralForm.priority' => 'пріоритет',
            'encounterReferralForm.started_at' => 'дата початку',
            'encounterReferralForm.ended_at' => 'дата закінчення',
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
            Session::flash('success', 'Заявку на електронне направлення створено. Підпишіть КЕП.');
            $this->showSignatureModal = true;
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->encounterReferralWarningMessage = $exception->getFormattedMessage();
            Session::flash('error', $this->encounterReferralWarningMessage);
        } catch (\Throwable $exception) {
            Log::error('EncounterEdit: failed to create encounter referral: '.$exception->getMessage());
            $this->encounterReferralWarningMessage = 'Не вдалося створити направлення: '.$exception->getMessage();
            Session::flash('error', $this->encounterReferralWarningMessage);
        }
    }

    public function signEncounterReferral(): void
    {
        if (empty($this->encounterReferralRequestIdToSign)) {
            Session::flash('error', 'Не вибрано направлення для підписання');
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

        $requestRecord = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->serviceForEncounter(
                (string) $this->encounterReferralRequestIdToSign,
                $encounter
            );

        try {
            $validated = $this->form->validate($this->form->signingRules());
            $person = Person::find($encounter->person_id);
            if ($person === null || empty($person->uuid)) {
                throw new \RuntimeException('Пацієнта не знайдено');
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

            $signPayload = (new ServiceRequestMapper())->toCreateSignedContent($dbData, $uuids, null, null);

            $signedContent = signatureService()->signData(
                $signPayload,
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = $lifecycle->submitSignedCreate('service_request', $person->uuid, $signedContent);

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
                        \App\Models\MedicalEvents\Sql\ServiceRequestRequest::where('uuid', $dbData['uuid'])
                            ->update(['request_number' => $dbData['request_number']]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('EncounterEdit: failed to fetch remote referral for number: '.$e->getMessage());
                }
            }

            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->encounterReferralRequestIdToSign = null;
            $this->form->resetSigningFields();

            $referralIdentifier = $dbData['request_number'] ?? $dbData['uuid'];
            Session::flash('success', 'Електронне направлення успішно створено без плану лікування. Номер направлення: '.$referralIdentifier);
        } catch (EHealthValidationException $exception) {
            $exception->report();
            Session::flash('error', $exception->getFormattedMessage());
            $this->showSignatureModal = false;
            $this->actionType = null;
        } catch (\Throwable $exception) {
            Log::error('EncounterEdit: failed to sign encounter referral: '.$exception->getMessage());
            Session::flash('error', 'Не вдалося підписати направлення: '.$exception->getMessage());
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
        } catch (\Throwable $exception) {
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
        } catch (\Throwable $exception) {
            Log::warning('EncounterEdit: failed to load service programs for standalone referral: '.$exception->getMessage());
            $this->encounterReferralPrograms = [];
        }
    }
}
