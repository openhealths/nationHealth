<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Core\Arr;
use App\Core\BaseForm as Form;
use App\Enums\Person\ServiceRequestStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Illuminate\Validation\ValidationException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\DeviceRequestRequestRepository;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\WithFileUploads;

class PatientReferrals extends BasePatientComponent
{
    use WithFileUploads;

    public Form $form;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    /** @var list<array<string, mixed>> */
    public array $referrals = [];

    public string $filterStatus = '';

    public string $filterStartedAtFrom = '';

    public string $filterStartedAtTo = '';

    public string $filterEndedAtFrom = '';

    public string $filterEndedAtTo = '';

    public ?string $expandedUuid = null;

    public ?string $requestIdToSign = null;

    public string $requestKindToSign = 'service_request';

    public string $referralExplanatoryLetter = '';

    protected function initializeComponent(): void
    {
        $this->loadReferrals();
    }

    public function loadReferrals(): void
    {
        if ($this->personId === null) {
            $this->referrals = [];

            return;
        }

        $filters = [
            'status' => $this->filterStatus !== '' ? $this->filterStatus : null,
            'started_at_from' => $this->filterStartedAtFrom !== '' ? $this->filterStartedAtFrom : null,
            'started_at_to' => $this->filterStartedAtTo !== '' ? $this->filterStartedAtTo : null,
            'ended_at_from' => $this->filterEndedAtFrom !== '' ? $this->filterEndedAtFrom : null,
            'ended_at_to' => $this->filterEndedAtTo !== '' ? $this->filterEndedAtTo : null,
        ];

        $rows = array_merge(
            app(ServiceRequestRequestRepository::class)->searchByPersonId($this->personId, $filters),
            app(DeviceRequestRequestRepository::class)->searchByPersonId($this->personId, $filters),
        );

        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftDate = (string) ($left['startedAt'] ?? '');
                $rightDate = (string) ($right['startedAt'] ?? '');

                return [$rightDate, (int) ($right['id'] ?? 0)] <=> [$leftDate, (int) ($left['id'] ?? 0)];
            }
        );

        $this->referrals = $rows;
    }

    public function applyFilters(): void
    {
        $this->loadReferrals();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStatus',
            'filterStartedAtFrom',
            'filterStartedAtTo',
            'filterEndedAtFrom',
            'filterEndedAtTo',
        ]);
        $this->loadReferrals();
    }

    public function toggleDetails(string $uuid): void
    {
        $this->expandedUuid = $this->expandedUuid === $uuid ? null : $uuid;
    }

    public function openSign(string $uuid, string $kind): void
    {
        $this->ownedReferral($uuid);
        $this->requestIdToSign = $uuid;
        $this->requestKindToSign = $kind === 'device_request' ? 'device_request' : 'service_request';
        $this->actionType = $this->requestKindToSign === 'device_request'
            ? 'sign_devicerequest'
            : 'sign_referral';
        $this->showSignatureModal = true;
    }

    public function sign(): void
    {
        if (in_array($this->actionType, ['sign_referral', 'sign_devicerequest'], true)) {
            $this->signDraft();

            return;
        }

        if ($this->actionType === 'recall_referral') {
            $this->signRecallReferral();

            return;
        }

        if ($this->actionType === 'cancel_referral') {
            $this->signCancelReferral();
        }
    }

    public function recallReferral(string $uuid, string $kind): void
    {
        $this->ownedReferral($uuid);
        if ($kind !== 'service_request') {
            $this->flashOutcome('error', __('care-plan.referral_recall_service_only'));

            return;
        }

        $this->requestIdToSign = $uuid;
        $this->requestKindToSign = 'service_request';
        $this->referralExplanatoryLetter = '';
        $this->actionType = 'recall_referral';
        $this->showSignatureModal = true;
    }

    public function cancelReferral(string $uuid, string $kind): void
    {
        $this->ownedReferral($uuid);
        $this->requestIdToSign = $uuid;
        $this->requestKindToSign = $kind === 'device_request' ? 'device_request' : 'service_request';
        $this->actionType = 'cancel_referral';
        $this->showSignatureModal = true;
    }

    public function signRecallReferral(): void
    {
        if (empty($this->requestIdToSign)) {
            $this->flashOutcome('error', 'Не вибрано направлення для відкликання');
            $this->showSignatureModal = false;

            return;
        }

        $letter = trim($this->referralExplanatoryLetter);
        if ($letter === '') {
            $this->addError('referralExplanatoryLetter', __('care-plan.referral_recall_letter_required'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            $this->flashOutcome('error', $exception->validator->errors()->first());

            return;
        }

        $record = $this->ownedReferral((string) $this->requestIdToSign);
        if (!$record instanceof ServiceRequestRequest) {
            $this->flashOutcome('error', __('care-plan.referral_recall_service_only'));
            $this->showSignatureModal = false;

            return;
        }

        try {
            $payload = ['explanatory_letter' => $letter];
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = app(ReferralRequestLifecycleService::class)->submitSignedRecall($this->uuid, $record->uuid, [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
                'explanatory_letter' => $letter,
            ]);

            $this->persistReferralStatusFromJob($finalResponse, $record, ServiceRequestStatus::RECALLED);
            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->requestIdToSign = null;
            $this->referralExplanatoryLetter = '';
            $this->form->resetSigningFields();
            $this->loadReferrals();
            $this->flashOutcome('success', __('care-plan.referral_recall_success'));
        } catch (EHealthValidationException $exception) {
            $this->flashOutcome('error', $exception->getTranslatedMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('PatientReferrals: failed to recall referral: '.$exception->getMessage());
            $this->flashOutcome('error', 'Не вдалося відкликати направлення: '.$exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function signCancelReferral(): void
    {
        if (empty($this->requestIdToSign)) {
            $this->flashOutcome('error', 'Не вибрано направлення для скасування');
            $this->showSignatureModal = false;

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            $this->flashOutcome('error', $exception->validator->errors()->first());

            return;
        }

        $record = $this->ownedReferral((string) $this->requestIdToSign);
        $kind = $record instanceof ServiceRequestRequest ? 'service_request' : 'device_request';

        try {
            $payload = ['status_reason' => 'entered-in-error'];
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = app(ReferralRequestLifecycleService::class)->submitSignedCancel(
                $kind,
                $this->uuid,
                $record->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $payload['status_reason'],
                ]
            );

            $this->persistReferralStatusFromJob($finalResponse, $record, ServiceRequestStatus::ENTERED_IN_ERROR);
            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->requestIdToSign = null;
            $this->form->resetSigningFields();
            $this->loadReferrals();
            $this->flashOutcome('success', __('care-plan.referral_cancel_success'));
        } catch (EHealthValidationException $exception) {
            $this->flashOutcome('error', $exception->getTranslatedMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('PatientReferrals: failed to cancel referral: '.$exception->getMessage());
            $this->flashOutcome('error', 'Не вдалося скасувати направлення: '.$exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function signDraft(): void
    {
        if (empty($this->requestIdToSign)) {
            $this->flashOutcome('error', 'Не вибрано направлення для підписання');
            $this->showSignatureModal = false;

            return;
        }

        $requestRecord = $this->ownedReferral((string) $this->requestIdToSign);

        try {
            $validated = $this->form->validate($this->form->signingRules());
            $lifecycle = app(ReferralRequestLifecycleService::class);

            $activity = $requestRecord->basedOnId
                ? CarePlanActivity::query()->find($requestRecord->basedOnId)
                : null;
            $carePlan = $activity !== null
                ? CarePlan::query()->with(['encounter.episode', 'person'])->find($activity->carePlanId)
                : null;
            $encounter = $requestRecord->contextId
                ? Encounter::query()->with('episode')->find($requestRecord->contextId)
                : $carePlan?->encounter;

            if ($carePlan === null && $encounter === null) {
                throw new \RuntimeException('Не знайдено взаємодію або план лікування для направлення');
            }

            $context = $carePlan ?? $encounter;
            $actingEmployeeId = $requestRecord->employeeId ?? Auth::user()?->activeDoctorEmployee()?->id;
            $employeeContext = $context instanceof Encounter
                ? $lifecycle->resolveEncounterEmployeeContext($context, $actingEmployeeId)
                : $lifecycle->resolveEmployeeContext($carePlan, $activity, $actingEmployeeId);

            $dbData = $lifecycle->buildSignDbData($requestRecord, $activity, $context, $employeeContext);

            $uuids = [
                'person_uuid' => $this->uuid,
                'encounter_uuid' => $encounter?->uuid,
                'episode_uuid' => $encounter?->episode?->value ?? null,
                'employee_uuid' => $employeeContext['employee_uuid'],
                'legal_entity_uuid' => $employeeContext['legal_entity_uuid'],
            ];

            $kind = $this->requestKindToSign === 'device_request' ? 'device_request' : 'service_request';
            $mapper = $kind === 'service_request'
                ? new ServiceRequestMapper()
                : new DeviceRequestMapper();
            $signPayload = $mapper->toCreateSignedContent(
                $dbData,
                $uuids,
                $carePlan !== null ? (string) $carePlan->uuid : null,
                $activity !== null ? (string) $activity->uuid : null
            );

            $signedContent = signatureService()->signData(
                $signPayload,
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = $lifecycle->submitSignedCreate($kind, $this->uuid, $signedContent);

            $dbData = $lifecycle->persistAfterSignedCreate(
                $dbData,
                $finalResponse,
                $kind,
                (int) $this->personId
            );

            if ($activity !== null && $activity->status === 'scheduled') {
                $activity->update(['status' => 'in-progress']);
            }

            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->requestIdToSign = null;
            $this->form->resetSigningFields();
            $this->loadReferrals();
            $this->flashOutcome(
                'success',
                'Електронне направлення успішно підписано (№ '.($dbData['request_number'] ?? $dbData['uuid']).').'
            );
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->flashOutcome('error', $exception->getFormattedMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('PatientReferrals: failed to sign referral: '.$exception->getMessage());
            $this->flashOutcome('error', 'Не вдалося підписати направлення: '.$exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function resendSms(string $uuid, string $kind): void
    {
        $this->ownedReferral($uuid);

        try {
            $response = app(ReferralRequestLifecycleService::class)->resendSms($this->uuid, $uuid, $kind);

            if ($response->successful()) {
                $this->flashOutcome('success', __('care-plan.referral_sms_resent'));

                return;
            }

            $this->flashOutcome('error', 'Не вдалося повторно надіслати СМС');
        } catch (EHealthValidationException $exception) {
            $this->flashOutcome('error', $exception->getTranslatedMessage());
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() === 403) {
                $this->flashOutcome('warning', __('care-plan.referral_sms_forbidden'));

                return;
            }

            $this->flashOutcome('error', 'Помилка надсилання СМС: '.$exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('PatientReferrals: failed to resend SMS: '.$exception->getMessage());
            $this->flashOutcome('error', 'Помилка надсилання СМС: '.$exception->getMessage());
        }
    }

    public function loadReferralPrintoutForm(string $uuid): string
    {
        $record = $this->ownedReferral($uuid);

        $activity = $record->basedOnId ? CarePlanActivity::query()->find($record->basedOnId) : null;
        $carePlan = $activity !== null ? CarePlan::query()->find($activity->carePlanId) : null;
        $encounter = $record->contextId
            ? Encounter::query()->find($record->contextId)
            : $carePlan?->encounter;

        $context = $carePlan ?? $encounter;
        if ($context === null) {
            $this->flashOutcome('error', 'Не знайдено контекст для друку направлення.');

            return '';
        }

        try {
            return app(ReferralRequestLifecycleService::class)->buildPrintoutHtml($context, $uuid);
        } catch (\Throwable $exception) {
            Log::error('PatientReferrals: failed to load printout: '.$exception->getMessage());
            $this->flashOutcome('error', 'Не вдалося завантажити друковану форму.');

            return '';
        }
    }

    public function render(): View
    {
        return view('livewire.person.records.referrals');
    }

    protected function flashOutcome(string $type, string $message): void
    {
        session()->flash($type, $message);
        $this->dispatch('flashMessage', ['message' => $message, 'type' => $type]);
    }

    /**
     * @param  array<string, mixed>  $finalResponse
     */
    private function persistReferralStatusFromJob(
        array $finalResponse,
        ServiceRequestRequest|DeviceRequestRequest $record,
        ServiceRequestStatus $fallback
    ): void {
        $result = $finalResponse['result'] ?? null;
        $entity = is_array($result) ? ($result[0] ?? $result) : $finalResponse;
        $raw = is_array($entity) ? ($entity['status'] ?? null) : null;
        $resolved = is_string($raw) ? ServiceRequestStatus::resolve($raw) : null;

        $record->update([
            'status' => ($resolved ?? $fallback)->value,
        ]);
    }

    private function ownedReferral(string $uuid): ServiceRequestRequest|DeviceRequestRequest
    {
        abort_unless($this->personId !== null, 404);

        return app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->referralForPerson($uuid, $this->personId);
    }
}
