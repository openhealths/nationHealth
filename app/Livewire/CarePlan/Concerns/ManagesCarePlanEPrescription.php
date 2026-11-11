<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\MedicationRequest\Forms\PrescriptionRules;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\CarePlanActivityEHealthGuard;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use RuntimeException;
use Throwable;

trait ManagesCarePlanEPrescription
{
    public function initEPrescriptionForm(int $activityId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $activity = $this->ownedActivity($activityId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        $blockedActivityStatuses = ['cancelled', 'completed'];

        if ($this->isTerminalCarePlan) {
            Session::flash('error', __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => CarePlanStatus::labelFor($this->carePlan->status),
            ]));

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            Session::flash('error', __('Виписування рецепту заборонено: це призначення вже завершено або скасовано.'));

            return;
        }

        if ($activity->resolvedKind() !== 'medication_request') {
            Session::flash('error', __('care-plan.eprescription_wrong_activity_kind'));

            return;
        }

        try {
            app(CarePlanActivityEHealthGuard::class)->assertRegisteredInEHealth($this->carePlan, $activity);
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        $this->ePrescriptionSelectedProduct = null;
        $this->ePrescriptionWarningMessage = '';
        $this->ePrescriptionPackages = [];
        $this->ePrescriptionMultiples = [];

        try {
            $this->ePrescriptionSelectedProduct = $this->resolveDrugForActivity($activity);
            if ($this->ePrescriptionSelectedProduct && !empty($this->ePrescriptionSelectedProduct['packages'])) {
                $this->ePrescriptionPackages = $this->ePrescriptionSelectedProduct['packages'];
                $this->ePrescriptionMultiples = PrescriptionRules::quantities($this->ePrescriptionSelectedProduct);
            }
        } catch (Exception $e) {
            Log::warning('CarePlanShow: failed to fetch drug details: ' . $e->getMessage());
        }

        if (!$this->ePrescriptionSelectedProduct) {
            Session::flash('error', __('care-plan.medication_not_available_for_program'));

            return;
        }

        $this->ePrescriptionSelectedProgram = null;
        $this->ePrescriptionSkipTreatmentPeriod = true;
        if (!empty($activity->program)) {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $activity->program);
            if ($program) {
                $this->ePrescriptionSelectedProgram = $program;
                $settings = $this->ePrescriptionSelectedProgram['settings'] ?? [];
                $this->ePrescriptionSkipTreatmentPeriod = filter_var($settings['skip_treatment_period'] ?? true, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // The patient's authentication method is legally significant: it must come from the
        // eHealth registry, never from a local fallback.
        $this->ePrescriptionAuthMethods = [];
        if (!$this->carePlanPrescriptionNotificationsDisabled) {
            try {
                $this->ePrescriptionAuthMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->validate();
            } catch (Exception $e) {
                Log::channel('e_health_errors')->error('CarePlanShow: failed to fetch patient auth methods', [
                    'care_plan_id' => $this->carePlan->id,
                    'activity_id' => $activity->id,
                    'exception' => $e->getMessage(),
                ]);
                Session::flash('error', __('care-plan.auth_methods_unavailable'));

                return;
            }

            if (empty($this->ePrescriptionAuthMethods)) {
                Session::flash('error', __('care-plan.auth_methods_empty'));

                return;
            }
        }

        $issuedQty = MedicalEventsRepository::medicationRequest()->issuedCarePlanQuantity($activity->uuid);

        $activityQty = $activity->quantity;
        $this->ePrescriptionRemainingQty = $activityQty === null
            ? 1.0
            : max(0.0, (float) $activityQty - (float) $issuedQty);

        try {
            $eHealthActivity = EHealth::carePlanActivity()->getDetails(
                (string) $this->carePlan->person->uuid,
                (string) $this->carePlan->uuid,
                (string) $activity->uuid
            )->validate();
            if ($eHealthActivity['id'] !== $activity->uuid) {
                throw new RuntimeException('Activity details do not match the requested resource.');
            }
            $eHealthRemaining = data_get($eHealthActivity, 'detail.remainingQuantity.value');
            if ($eHealthRemaining !== null) {
                $this->ePrescriptionRemainingQty = max(0.0, (float) $eHealthRemaining);
            }
        } catch (Exception $e) {
            Log::warning('CarePlanShow: failed to fetch eHealth activity remaining qty: ' . $e->getMessage());
        }

        if ($activityQty === null) {
            $this->ePrescriptionWarningMessage = __('У призначенні плану лікування не вказано кількість. Перевірте дані в ЕСОЗ перед підписанням рецепту.');
        }

        $unit = (new MedicationRequestMapper())->medicationUnit($this->ePrescriptionSelectedProduct);
        $this->ePrescriptionMultiples = PrescriptionRules::quantities($this->ePrescriptionSelectedProduct, $this->ePrescriptionRemainingQty);
        $packageStep = $this->resolveMedicationPackageStep($this->ePrescriptionSelectedProduct ?? []);

        if ($packageStep > 0 && $this->ePrescriptionRemainingQty > 0 && $this->ePrescriptionRemainingQty < $packageStep) {
            $message = __('care-plan.medication_remaining_below_packaging', [
                'remaining' => $this->ePrescriptionRemainingQty,
                'count' => $packageStep,
            ]);
            Session::flash('error', $message);

            return;
        }

        $defaultQty = !empty($this->ePrescriptionMultiples)
            ? $this->ePrescriptionMultiples[0]
            : $packageStep;

        if ($this->ePrescriptionRemainingQty > 0 && $defaultQty > $this->ePrescriptionRemainingQty) {
            $defaultQty = $this->ePrescriptionRemainingQty;
            if (!$this->isMedicationQtyDivisible($defaultQty, $this->ePrescriptionSelectedProduct ?? [])) {
                $defaultQty = $packageStep <= $this->ePrescriptionRemainingQty
                    ? $packageStep
                    : $this->ePrescriptionRemainingQty;
            }
        }

        $this->ePrescriptionShowDailyDoseWarning = false;
        $this->ePrescriptionShowRemainingQtyWarning = false;
        $this->ePrescriptionRemainingQtyWarningMessage = '';
        $this->ePrescriptionSelectedActivity = $activity->toArray();

        $employeeContext = app(MedicationRequestLifecycleService::class)
            ->resolveEmployeeContext($this->carePlan, null, Auth::user()->activeDoctorEmployee()?->id);
        $eligibleEncounters = app(MedicationRequestLifecycleService::class)
            ->findEligibleEncountersForEPrescription(
                (int) $this->carePlan->person_id,
                $employeeContext['employee_uuid'] ?? null
            );

        $this->ePrescriptionEligibleEncounters = $eligibleEncounters
            ->map(static function ($encounter): array {
                $endedAt = $encounter->period?->end;
                $dateLabel = $endedAt
                    ? Carbon::parse($endedAt)->format('d.m.Y H:i')
                    : ($encounter->created_at?->format('d.m.Y H:i') ?? '');

                return [
                    'id' => (int) $encounter->id,
                    'uuid' => (string) $encounter->uuid,
                    'label' => trim(__('Завершена взаємодія від :date (:uuid)', ['date' => $dateLabel, 'uuid' => $encounter->uuid])),
                ];
            })
            ->values()
            ->all();

        $defaultEncounterId = count($this->ePrescriptionEligibleEncounters) === 1
            ? (string) $this->ePrescriptionEligibleEncounters[0]['id']
            : '';

        // eHealth MedicationRequest expects a drug id; activity.productReference is INNM dosage.
        $resolvedMedicationId = (string) ($this->ePrescriptionSelectedProduct['id'] ?? $activity->productReference);

        $this->ePrescriptionForm = [
            'activity_id' => $activity->id,
            'encounter_id' => $defaultEncounterId,
            'medication_id' => $resolvedMedicationId,
            'started_at' => now()->toDateString(),
            'duration' => 10,
            'dose_input_unit' => 'denominator',
            'dose_ingredient_id' => $this->ePrescriptionSelectedProduct['ingredients'][0]['id'] ?? '',
            'dose_warning_confirmed' => false,
            'phone_confirmed' => false,
            'ended_at' => '',
            'medication_qty' => $defaultQty,
            'medication_unit' => $unit,
            'signature_text' => '',
            'max_dose_per_period' => (float) $activity->dailyAmount ?: 1.0,
            'max_dose_per_administration' => 1.0,
            'inform_with' => !empty($this->ePrescriptionAuthMethods) ? ($this->ePrescriptionAuthMethods[0]['uuid'] ?? '') : '',
            'container_dosage' => '',
            'program_id' => $activity->program,
            'note' => '',
            'route' => 'oral',
        ];

        // Prefer OTP/THIRD_PERSON option value with pipe encoding when available.
        if (!empty($this->ePrescriptionAuthMethods)) {
            $first = $this->ePrescriptionAuthMethods[0];
            $methodId = $first['uuid'] ?? ($first['id'] ?? '');
            $type = $first['type'] ?? '';
            $valueLabel = $first['phone_number'] ?? ($first['alias'] ?? '');
            if ($methodId !== '') {
                $this->ePrescriptionForm['inform_with'] = "{$methodId}|{$type}|{$valueLabel}";
            }
        }

        if ($this->ePrescriptionEligibleEncounters === []) {
            Session::flash('error', __('care-plan.eprescription_encounter_none'));

            return;
        }

        $this->calculateTreatmentDates();
        $this->showEPrescriptionDrawer = true;
    }

    public function updatedEPrescriptionForm($value, $name): void
    {
        $this->ePrescriptionWarningMessage = '';
        $this->ePrescriptionShowDailyDoseWarning = false;
        if (in_array($name, ['max_dose_per_period', 'max_dose_per_administration', 'dose_input_unit', 'dose_ingredient_id'], true)) {
            $this->ePrescriptionForm['dose_warning_confirmed'] = false;
        }
        if ($name === 'inform_with') {
            $this->ePrescriptionForm['phone_confirmed'] = false;
        }

        if (str_contains($name, 'started_at') || str_contains($name, 'duration')) {
            $this->calculateTreatmentDates();
        }
    }

    public function calculateTreatmentDates(): void
    {
        if (empty($this->ePrescriptionForm['started_at']) || empty($this->ePrescriptionForm['duration'])) {
            return;
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $this->ePrescriptionForm['started_at']);
            $duration = (int) $this->ePrescriptionForm['duration'];

            if ($duration < 1) {
                return;
            }

            $maxPeriod = PrescriptionRules::programSetting($this->ePrescriptionSelectedProgram, 'request_max_period_day');
            if ($maxPeriod !== null && $duration > (int) $maxPeriod) {
                $this->ePrescriptionWarningMessage = __('Тривалість курсу лікування (:duration днів) перевищує максимальний період курсу за обраною програмою (:max_period днів).', ['duration' => $duration, 'max_period' => $maxPeriod]);
            } else {
                $this->ePrescriptionWarningMessage = '';
            }

            $end = $start->copy()->addDays($duration - 1);
            $this->ePrescriptionForm['ended_at'] = $end->toDateString();
        } catch (Exception $e) {
            // Invalid date format
        }
    }

    public function confirmExceededDailyDose(bool $confirm): void
    {
        $this->ePrescriptionShowDailyDoseWarning = false;
        $this->ePrescriptionForm['dose_warning_confirmed'] = $confirm;
        if ($confirm) {
            $this->validateEPrescription();
        }
    }

    public function validateEPrescription(): void
    {
        $this->ePrescriptionWarningMessage = '';
        $this->ePrescriptionShowDailyDoseWarning = false;

        try {
            $this->validate([
                'ePrescriptionForm.encounter_id' => 'required|integer|min:1',
                'ePrescriptionForm.medication_id' => 'required|string|uuid',
                'ePrescriptionForm.inform_with' => 'nullable|string',
                'ePrescriptionForm.signature_text' => 'required|string',
                'ePrescriptionForm.max_dose_per_administration' => 'required|numeric|min:0.01',
                'ePrescriptionForm.max_dose_per_period' => 'required|numeric|min:0.01',
                'ePrescriptionForm.medication_qty' => 'required|numeric|min:0.01',
                'ePrescriptionForm.started_at' => 'required|date',
                'ePrescriptionForm.duration' => 'required|integer|min:1',
                'ePrescriptionForm.ended_at' => 'required|date|after_or_equal:ePrescriptionForm.started_at',
            ], [
                'ePrescriptionForm.encounter_id.required' => __('care-plan.eprescription_encounter_required'),
                'ePrescriptionForm.signature_text.required' => __('care-plan.eprescription_signature_required'),
                'ePrescriptionForm.ended_at.after_or_equal' => __('errors.ehealth.messages.period_end_before_start'),
            ], [
                'ePrescriptionForm.encounter_id' => __('care-plan.encounter'),
                'ePrescriptionForm.medication_id' => __('care-plan.medication'),
                'ePrescriptionForm.inform_with' => __('encounters.eprescription.patient_authentication'),
                'ePrescriptionForm.signature_text' => __('medication-requests.signatura'),
                'ePrescriptionForm.max_dose_per_administration' => __('encounters.eprescription.max_dose_per_administration'),
                'ePrescriptionForm.max_dose_per_period' => __('encounters.eprescription.max_dose_per_period'),
                'ePrescriptionForm.medication_qty' => __('medication-requests.kil_kist'),
                'ePrescriptionForm.started_at' => __('encounters.eprescription.course_start'),
                'ePrescriptionForm.duration' => __('medication-requests.treatment_duration'),
                'ePrescriptionForm.ended_at' => __('encounters.eprescription.course_end'),
            ]);
        } catch (ValidationException $exception) {
            $this->ePrescriptionWarningMessage = $exception->validator->errors()->first();
            $this->setErrorBag($exception->validator->getMessageBag());
            Session::flash('error', $this->ePrescriptionWarningMessage);
            $this->dispatch('scroll-to-error');

            return;
        }

        try {
            $this->ePrescriptionForm = PrescriptionRules::normalize(
                $this->ePrescriptionForm,
                $this->ePrescriptionSelectedProduct ?? [],
                $this->ePrescriptionSelectedProgram ?? [],
                (float) ($this->ePrescriptionSelectedActivity['daily_amount'] ?? ($this->ePrescriptionSelectedActivity['dailyAmount'] ?? 0))
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('ePrescriptionForm.'.$field, $messages[0]);
            }
            $this->ePrescriptionShowDailyDoseWarning = array_key_exists('dose_warning_confirmed', $exception->errors());
            $this->ePrescriptionWarningMessage = $exception->validator->errors()->first();
            Session::flash($this->ePrescriptionShowDailyDoseWarning ? 'warning' : 'error', $this->ePrescriptionWarningMessage);

            return;
        }
        $qty = (float) $this->ePrescriptionForm['medication_qty'];

        if ($qty > $this->ePrescriptionRemainingQty && $this->ePrescriptionSelectedActivity['quantity'] !== null) {
            $this->ePrescriptionWarningMessage = __('Кількість ЛЗ в рецепті (:quantity) перевищує залишкову кількість у плані лікування (:remaining). Виписування неможливе.', ['quantity' => $qty, 'remaining' => $this->ePrescriptionRemainingQty]);
            Session::flash('error', $this->ePrescriptionWarningMessage);

            return;
        }

        if (!$this->ePrescriptionSkipTreatmentPeriod) {
            $lastActivePrescription = MedicalEventsRepository::medicationRequest()->lastActiveForTreatment($this->carePlan->personId, $this->ePrescriptionForm['medication_id']);

            if ($lastActivePrescription && $lastActivePrescription->endedAt) {
                $lastEnd = Carbon::parse($lastActivePrescription->endedAt);
                $today = now();
                $remainingDays = $today->diffInDays($lastEnd, false);

                if ($remainingDays > 0) {
                    $prevDuration = $lastActivePrescription->startedAt ? Carbon::parse($lastActivePrescription->startedAt)->diffInDays($lastEnd) + 1 : 10;
                    $allowedDaysBeforeEnd = $prevDuration >= 21 ? 7 : 3;

                    if ($remainingDays > $allowedDaysBeforeEnd) {
                        $this->ePrescriptionWarningMessage = __('Повторний Е-Рецепт на той же МНН можна виписати за :allowed_days днів до закінчення терміну лікування попереднього Е-Рецепту. Попередній рецепт діє до :date (залишилось :remaining_days днів).', ['allowed_days' => $allowedDaysBeforeEnd, 'date' => $lastEnd->format('d.m.Y'), 'remaining_days' => $remainingDays]);
                        Session::flash('error', $this->ePrescriptionWarningMessage);

                        return;
                    }
                }
            }
        }

        $this->submitEPrescriptionRequest();
    }

    public function submitEPrescriptionRequest(): void
    {
        try {
            $employeeContext = app(MedicationRequestLifecycleService::class)
                ->resolveEmployeeContext($this->carePlan, null, Auth::user()->activeDoctorEmployee()?->id);
            $activity = $this->ownedActivity((int) $this->ePrescriptionForm['activity_id']);

            $uuid = app(MedicationRequestLifecycleService::class)->createCarePlanDraft(
                $this->carePlan,
                $activity,
                $this->ePrescriptionForm,
                $employeeContext
            );

            $this->showEPrescriptionDrawer = false;
            $this->ePrescriptionRequestIdToSign = $uuid;
            Session::flash('success', __('Заявку на е-рецепт створено. Підпишіть КЕП.'));
            $this->openSignatureModal('sign_eprescription');

        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
        } catch (EHealthValidationException $exception) {
            $exception->report();
            Session::flash('error', $exception->getTranslatedMessage());
        } catch (EHealthResponseException $e) {
            if ($e->getCode() === 403 || $e->response->status() === 403) {
                Log::warning('CarePlanShow: 403 access denied when submitting ePrescription. Prompting for approval.');
                Session::flash('warning', __('Відсутній доступ до медичних даних. Будь ласка, надішліть запит на доступ пацієнту.'));
                $this->openMethodSelectionModal();
            } else {
                Log::error('CarePlanShow: failed to create ePrescription API error: ' . $e->getMessage());
                Session::flash('error', __('Не вдалося створити заявку на рецепт: ') . $e->getMessage());
            }
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to create ePrescription: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося створити заявку на рецепт: ') . $e->getMessage());
        }
    }

    #[Locked]
    public array $carePlanImportedPrescriptions = [];

    public array $carePlanPrescriptionFilters = ['employee_id' => '', 'status' => '', 'request_number' => '', 'created_from' => '', 'created_to' => '', 'started_at_from' => '', 'started_at_to' => '', 'ended_at_from' => '', 'ended_at_to' => ''];

    #[Locked]
    public ?string $prescriptionSmsConfirmationId = null;

    #[Locked]
    public array $ePrescriptionDispenses = [];

    public function syncEPrescriptions(): void
    {
        if (Auth::user()->cannot('care_plan:read') || Auth::user()->cannot('medication_request:read') || Auth::user()->cannot('medication_request_request:read')) {
            Session::flash('error', __('medication-requests.permission_denied'));

            return;
        }
        try {
            $filters = array_filter($this->carePlanPrescriptionFilters, static fn (string $value): bool => $value !== '');
            $lifecycle = app(MedicationRequestLifecycleService::class);
            $activeItems = $lifecycle->getByCarePlan($this->carePlan->uuid, $filters);
            $draftItems = $lifecycle->getRequestsByCarePlan($this->carePlan->uuid, array_intersect_key($filters, array_flip(['employee_id', 'status'])));
            $draftItems = $lifecycle->filterDocuments($draftItems, $filters);
            // Match confirmed prescriptions against the pre-import requests. A newly
            // imported MR must not hide its older unsigned MRR with the same number.
            $localRequests = Repository::carePlanActivity()->linkedDocuments($this->carePlan->activities->pluck('uuid')->all(), 'medication_request');
            $updatedCount = MedicalEventsRepository::medicationRequest()->importCarePlanDocuments($this->carePlan, $activeItems, $draftItems);
            MedicalEventsRepository::medicationRequest()->synchronizeCarePlanDocuments($localRequests, $activeItems, $draftItems);
            $this->carePlanImportedPrescriptions = array_merge(
                array_map(static fn (array $item): array => $item + ['_resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION], $activeItems),
                array_map(static fn (array $item): array => $item + ['_resource_type' => MedicationRequestRequest::TYPE_REQUEST], $draftItems)
            );
            $this->refreshCarePlan();
            Session::flash('success', __('medication-requests.care_plan_sync', ['count' => $updatedCount]));
        } catch (Exception $exception) {
            Log::warning('Care-plan prescription synchronization failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.search_failed'));
        }
    }

    public function signEPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            Session::flash('error', __('Не вибрано заявку на рецепт для підписання'));
            $this->showSignatureModal = false;

            return;
        }

        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson((string) $this->ePrescriptionRequestIdToSign, (int) $this->carePlan->personId);

            $result = app(MedicationRequestLifecycleService::class)->signPrescription(
                $this->carePlan,
                $requestRecord,
                array_merge($this->form, [
                    'request_notification_disabled' => filter_var(
                        PrescriptionRules::programSetting($this->ePrescriptionSelectedProgram, 'request_notification_disabled', false),
                        FILTER_VALIDATE_BOOLEAN
                    ),
                    'medication_unit' => $this->ePrescriptionForm['medication_unit'] ?? 'од.',
                    'signer_tax_id' => Auth::user()?->party?->taxId,
                ]),
                $requestRecord->informWith ?? '',
                $this->ePrescriptionRemainingQty
            );

            if (!empty($result['show_remaining_qty_warning']) || !empty($result['warning_message'])) {
                $this->ePrescriptionShowRemainingQtyWarning = true;
                $this->ePrescriptionRemainingQtyWarningMessage = (string) ($result['warning_message'] ?? '');
            }

            if (!empty($result['warning_message'])) {
                Session::flash('warning', $result['warning_message']);
            }

            Session::flash('success', $result['success_message']);
            $this->showSignatureModal = false;
            $this->ePrescriptionRequestIdToSign = null;
            try {
                $this->refreshCarePlan();
            } catch (Throwable $exception) {
                Log::warning('Signed prescription page refresh is pending', ['exceptionType' => $exception::class]);
                Session::flash('warning', trim(($result['warning_message'] ?? '')."\n".__('care-plan.eprescription_signed_refresh_pending')));
            }

        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign E-Prescription validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to sign E-Prescription: ' . $e->getMessage());
            Session::flash('error', __('Помилка при підписанні рецепту: ') . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function rejectPrescription(string $requestId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson($requestId, (int) $this->carePlan->personId);

            if (in_array(strtolower((string) $requestRecord->status), ['new', 'draft'], true)) {
                app(MedicationRequestLifecycleService::class)->rejectPrescription($this->carePlan, $requestRecord);
                $this->refreshCarePlan();
                Session::flash('success', __('Електронний рецепт успішно відхилено.'));
            } else {
                $this->ePrescriptionRequestIdToSign = $requestId;
                $this->openSignatureModal('reject_prescription');
            }
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося відхилити рецепт: ') . $e->getMessage());
        }
    }

    public function signRejectPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            Session::flash('error', __('Не вибрано рецепт для відхилення'));
            $this->showSignatureModal = false;

            return;
        }

        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson((string) $this->ePrescriptionRequestIdToSign, (int) $this->carePlan->personId);

            app(MedicationRequestLifecycleService::class)->rejectPrescription(
                $this->carePlan,
                $requestRecord,
                array_merge($this->form, ['signer_tax_id' => Auth::user()?->party?->taxId]),
                $this->statusReason
            );

            $this->showSignatureModal = false;
            $this->refreshCarePlan();
            Session::flash('success', __('Електронний рецепт успішно відхилено.'));

        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            $errorMsg = __('Не вдалося відхилити рецепт: ') . $e->getMessage();
            Session::flash('error', $errorMsg);
            $this->showSignatureModal = false;
        }
    }

    public function requestPrescriptionSms(string $prescriptionId): void
    {
        $record = app(MedicalRequestOwnership::class)->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);
        if (Auth::user()->cannot('resend', $record)) {
            Session::flash('error', __('medication-requests.permission_denied'));

            return;
        }
        $this->prescriptionSmsConfirmationId = $prescriptionId;
    }

    public function resendPrescriptionSms(string $prescriptionId): void
    {
        $record = app(MedicalRequestOwnership::class)->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);
        if (Auth::user()->cannot('resend', $record) || $this->prescriptionSmsConfirmationId !== $prescriptionId) {
            Session::flash('error', __('medication-requests.sms_confirmation'));

            return;
        }
        $this->prescriptionSmsConfirmationId = null;
        try {
            app(MedicationRequestLifecycleService::class)->resendSms($this->carePlan->person->uuid, $prescriptionId);
            Session::flash('success', __('medication-requests.sms_sent'));
        } catch (Exception $exception) {
            Log::warning('Prescription SMS resend failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.sms_unavailable'));
        }
    }

    public function loadPrintoutForm(string $prescriptionId): string
    {
        try {
            $record = app(MedicalRequestOwnership::class)
                ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);
            if (Auth::user()->cannot('view', $record)) {
                Session::flash('error', __('medication-requests.permission_denied'));

                return '';
            }

            // Only the validated official patient leaflet may be presented as a prescription printout.
            $this->printableContent = app(MedicationRequestLifecycleService::class)->fetchPrintoutFromEhealth(
                $this->carePlan->person->uuid,
                $prescriptionId
            );
            $this->dispatch('printoutLoaded');

            return $this->printableContent;
        } catch (Exception $exception) {
            Log::warning('Prescription printout unavailable', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.printout_unavailable'));

            return '';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveDrugForActivity(CarePlanActivity $activity): ?array
    {
        if (empty($activity->productReference)) {
            return null;
        }

        $filters = ['innm_dosage_id' => $activity->productReference];
        if (!empty($activity->program)) {
            $filters['medical_program_id'] = $activity->program;
        }

        $data = EHealth::drug()->getMany($filters)->validate();
        if (!empty($data[0])) {
            return $data[0];
        }

        $fallback = EHealth::drug()->getMany(['innm_id' => $activity->productReference])->validate();

        return $fallback[0] ?? null;
    }

    protected function resolveMedicationPackageStep(array $drug): float
    {
        $steps = collect($drug['packages'] ?? [])->map(static fn (array $package): float => (float) ($package['package_min_qty'] ?? ($package['min_qty'] ?? ($package['package_qty'] ?? 0))))->filter(static fn (float $step): bool => $step > 0);

        return $steps->min() ?? 0.0;
    }

    protected function isMedicationQtyDivisible(float $qty, array $drug): bool
    {
        return PrescriptionRules::isAllowedQuantity($qty, $drug);
    }

    #[Computed]
    public function carePlanPrescriptionContainers(): array
    {
        return PrescriptionRules::containers($this->ePrescriptionSelectedProduct ?? []);
    }

    #[Computed]
    public function carePlanPrescriptionIngredientDoses(): array
    {
        try {
            return (new MedicationRequestMapper())->ingredientDoses((float) ($this->ePrescriptionForm['max_dose_per_period'] ?? 0), $this->ePrescriptionForm, $this->ePrescriptionSelectedProduct ?? []);
        } catch (Exception) {
            return [];
        }
    }

    #[Computed]
    public function carePlanPrescriptionNotificationsDisabled(): bool
    {
        return filter_var(PrescriptionRules::programSetting($this->ePrescriptionSelectedProgram, 'request_notification_disabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Show the session message in this response and scroll to the invalid field.
     */
    protected function failEPrescriptionField(string $message, string $field): void
    {
        $this->ePrescriptionWarningMessage = $message;
        $this->addError($field, $message);
        Session::flash('error', $message);
        $this->dispatch('scroll-to-error');
    }

    public function blockPrescription(string $prescriptionId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

            app(MedicationRequestLifecycleService::class)->block($this->carePlan->person->uuid, $prescriptionId, [
                'status_reason' => 'Призупинення або блокування призначення',
            ]);
            MedicalEventsRepository::medicationRequest()->saveStatus($requestRecord, 'blocked');
            $this->refreshCarePlan();
            Session::flash('success', __('Рецепт успішно заблоковано в ЕСОЗ.'));
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to block prescription: ' . $e->getMessage());
            Session::flash('error', __('Помилка блокування рецепту: ') . $e->getMessage());
        }
    }

    public function unblockPrescription(string $prescriptionId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

            app(MedicationRequestLifecycleService::class)->unblock($this->carePlan->person->uuid, $prescriptionId, []);
            MedicalEventsRepository::medicationRequest()->saveStatus($requestRecord, 'active');
            $this->refreshCarePlan();
            Session::flash('success', __('Рецепт успішно розблоковано в ЕСОЗ.'));
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to unblock prescription: ' . $e->getMessage());
            Session::flash('error', __('Помилка розблокування рецепту: ') . $e->getMessage());
        }
    }

    public function checkDispenseHistory(string $prescriptionId): void
    {
        $record = app(MedicalRequestOwnership::class)->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);
        if (Auth::user()->cannot('dispenses', $record)) {
            Session::flash('error', __('medication-requests.permission_denied'));

            return;
        }
        try {
            $this->ePrescriptionDispenses = app(MedicationRequestLifecycleService::class)->getDispenseHistory($this->carePlan->person->uuid, $prescriptionId);
            if ($this->ePrescriptionDispenses === []) {
                Session::flash('info', __('medication-requests.dispenses_empty'));
            }
        } catch (Exception $exception) {
            $this->ePrescriptionDispenses = [];
            Log::warning('Prescription dispense history failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.detail_unavailable'));
        }
    }
}
