<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\CarePlanActivityRepository;
use App\Services\MedicalEvents\CarePlanActivityEHealthGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait ManagesCarePlanEPrescription
{
    public function initEPrescriptionForm(int $activityId, CarePlanActivityRepository $activityRepository): void
    {
        $this->authorizeCarePlanWrite();

        $activity = $this->ownedActivity($activityId);

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        $blockedActivityStatuses = ['cancelled', 'completed'];

        if ($this->isTerminalCarePlan) {
            $this->flashOutcome('error', __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => \App\Enums\CarePlanStatus::labelFor($this->carePlan->status),
            ]));

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            $this->flashOutcome('error', 'Виписування рецепту заборонено: це призначення вже завершено або скасовано.');

            return;
        }

        if ($activity->resolvedKind() !== 'medication_request') {
            $this->flashOutcome('error', __('care-plan.eprescription_wrong_activity_kind'));

            return;
        }

        try {
            app(CarePlanActivityEHealthGuard::class)->assertRegisteredInEHealth($this->carePlan, $activity);
        } catch (\RuntimeException $exception) {
            $this->flashOutcome('error', $exception->getMessage());

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
                $minQty = $this->resolveMedicationPackageStep($this->ePrescriptionSelectedProduct);
                $multiples = [];
                for ($i = 1; $i <= 10; $i++) {
                    $multiples[] = $minQty * $i;
                }
                $this->ePrescriptionMultiples = $multiples;
            }
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to fetch drug details: ' . $e->getMessage());
        }

        if (!$this->ePrescriptionSelectedProduct) {
            $this->ePrescriptionSelectedProduct = [
                'name' => $activity->productReference,
                'innm_dosage_form' => 'од.',
            ];
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
        try {
            $this->ePrescriptionAuthMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->getData();
        } catch (\Exception $e) {
            Log::channel('e_health_errors')->error('CarePlanShow: failed to fetch patient auth methods', [
                'care_plan_id' => $this->carePlan->id,
                'activity_id' => $activity->id,
                'exception' => $e->getMessage(),
            ]);
            $this->flashOutcome('error', __('care-plan.auth_methods_unavailable'));

            return;
        }

        if (empty($this->ePrescriptionAuthMethods)) {
            $this->flashOutcome('error', __('care-plan.auth_methods_empty'));

            return;
        }

        $issuedQty = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::whereHas('basedOn', fn($q) => $q->where('value', $activity->uuid))
            ->whereNotIn('status', \App\Repositories\MedicalEvents\MedicalEventsRequestStatuses::EXCLUDED_FROM_ISSUED_SUM)
            ->sum('medication_qty');

        $activityQty = $activity->quantity;
        $this->ePrescriptionRemainingQty = $activityQty === null
            ? 1.0
            : max(0.0, (float) $activityQty - (float) $issuedQty);

        try {
            $eHealthActivity = app(\App\Services\MedicalEvents\CarePlanActivityLifecycleService::class)->getDetails(
                (string) $this->carePlan->person->uuid,
                (string) $this->carePlan->uuid,
                (string) $activity->uuid
            );
            $eHealthRemaining = data_get($eHealthActivity, 'detail.remaining_quantity.value');
            if ($eHealthRemaining !== null) {
                $this->ePrescriptionRemainingQty = max(0.0, (float) $eHealthRemaining);
            }
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to fetch eHealth activity remaining qty: ' . $e->getMessage());
        }

        if ($activityQty === null) {
            $this->ePrescriptionWarningMessage = 'У призначенні плану лікування не вказано кількість. Перевірте дані в ЕСОЗ перед підписанням рецепту.';
        }

        $unit = $this->ePrescriptionSelectedProduct['innm_dosage_form'] ?? 'од.';
        $packageStep = $this->resolveMedicationPackageStep($this->ePrescriptionSelectedProduct ?? []);

        if ($packageStep > 0 && $this->ePrescriptionRemainingQty > 0 && $this->ePrescriptionRemainingQty < $packageStep) {
            $message = __('care-plan.medication_remaining_below_packaging', [
                'remaining' => $this->ePrescriptionRemainingQty,
                'count' => $packageStep,
            ]);
            $this->flashOutcome('error', $message);

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

        $employeeContext = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)
            ->resolveEmployeeContext($this->carePlan, null, Auth::user()?->activeDoctorEmployee()?->id);
        $eligibleEncounters = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)
            ->findEligibleEncountersForEPrescription(
                (int) $this->carePlan->person_id,
                $employeeContext['employee_uuid'] ?? null
            );

        $this->ePrescriptionEligibleEncounters = $eligibleEncounters
            ->map(static function ($encounter): array {
                $endedAt = $encounter->period?->end;
                $dateLabel = $endedAt
                    ? \Carbon\Carbon::parse($endedAt)->format('d.m.Y H:i')
                    : ($encounter->created_at?->format('d.m.Y H:i') ?? '');

                return [
                    'id' => (int) $encounter->id,
                    'uuid' => (string) $encounter->uuid,
                    'label' => trim("Завершена взаємодія від {$dateLabel} ({$encounter->uuid})"),
                ];
            })
            ->values()
            ->all();

        $defaultEncounterId = count($this->ePrescriptionEligibleEncounters) === 1
            ? (string) $this->ePrescriptionEligibleEncounters[0]['id']
            : '';

        $this->ePrescriptionForm = [
            'activity_id' => $activity->id,
            'encounter_id' => $defaultEncounterId,
            'medication_id' => $activity->productReference,
            'started_at' => now()->toDateString(),
            'duration' => 10,
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
            $methodId = $first['uuid'] ?? $first['id'] ?? '';
            $type = $first['type'] ?? '';
            $valueLabel = $first['phone_number'] ?? $first['alias'] ?? '';
            if ($methodId !== '') {
                $this->ePrescriptionForm['inform_with'] = "{$methodId}|{$type}|{$valueLabel}";
            }
        }

        if ($this->ePrescriptionEligibleEncounters === []) {
            $this->flashOutcome('error', __('care-plan.eprescription_encounter_none'));

            return;
        }

        $this->calculateTreatmentDates();
        $this->showEPrescriptionDrawer = true;
    }

    public function updatedEPrescriptionForm($value, $name): void
    {
        $this->ePrescriptionWarningMessage = '';
        $this->ePrescriptionShowDailyDoseWarning = false;

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
            $start = \Carbon\Carbon::createFromFormat('Y-m-d', $this->ePrescriptionForm['started_at']);
            $duration = (int) $this->ePrescriptionForm['duration'];

            if ($duration < 1) {
                return;
            }

            $maxPeriod = (int) ($this->ePrescriptionSelectedProgram['settings']['request_max_period_day'] ?? 90);
            if ($duration > $maxPeriod) {
                $this->ePrescriptionWarningMessage = "Тривалість курсу лікування ({$duration} днів) перевищує максимальний період курсу за обраною програмою ({$maxPeriod} днів).";
            } else {
                $this->ePrescriptionWarningMessage = '';
            }

            $end = $start->copy()->addDays($duration - 1);
            $this->ePrescriptionForm['ended_at'] = $end->toDateString();
        } catch (\Exception $e) {
            // Invalid date format
        }
    }

    public function confirmExceededDailyDose(bool $confirm): void
    {
        $this->ePrescriptionShowDailyDoseWarning = false;
        if ($confirm) {
            if (!str_starts_with($this->ePrescriptionForm['signature_text'], '(!)')) {
                $this->ePrescriptionForm['signature_text'] = '(!) ' . $this->ePrescriptionForm['signature_text'];
            }
            $this->submitEPrescriptionRequest();
        }
    }

    public function validateEPrescription(): void
    {
        $this->ePrescriptionWarningMessage = '';
        $this->ePrescriptionShowDailyDoseWarning = false;

        if (empty($this->ePrescriptionForm['encounter_id'])) {
            $this->failEPrescriptionField(
                __('care-plan.eprescription_encounter_required'),
                'ePrescriptionForm.encounter_id'
            );

            return;
        }

        if (empty($this->ePrescriptionForm['inform_with'])) {
            $this->failEPrescriptionField(
                'Необхідно обрати метод автентифікації пацієнта',
                'ePrescriptionForm.inform_with'
            );

            return;
        }

        $signatureText = trim((string) ($this->ePrescriptionForm['signature_text'] ?? ''));
        if ($signatureText === '') {
            $this->failEPrescriptionField(
                __('care-plan.eprescription_signature_required'),
                'ePrescriptionForm.signature_text'
            );

            return;
        }

        $maxDoseAdmin = (float) ($this->ePrescriptionForm['max_dose_per_administration'] ?? 0);
        $maxDosePeriod = (float) ($this->ePrescriptionForm['max_dose_per_period'] ?? 0);
        if ($maxDoseAdmin <= 0 || $maxDosePeriod <= 0) {
            $field = $maxDoseAdmin <= 0
                ? 'ePrescriptionForm.max_dose_per_administration'
                : 'ePrescriptionForm.max_dose_per_period';
            $this->failEPrescriptionField(__('care-plan.eprescription_dose_required'), $field);

            return;
        }

        $qty = (float) $this->ePrescriptionForm['medication_qty'];
        $maxDosage = (float) ($this->ePrescriptionSelectedProduct['packages'][0]['max_request_dosage'] ?? ($this->ePrescriptionSelectedProduct['max_request_dosage'] ?? 0));
        $packageStep = $this->resolveMedicationPackageStep($this->ePrescriptionSelectedProduct ?? []);

        if ($packageStep > 0 && !$this->isMedicationQtyDivisible($qty, $this->ePrescriptionSelectedProduct ?? [])) {
            $message = __('care-plan.medication_qty_packaging', ['count' => $packageStep]);
            $this->failEPrescriptionField($message, 'ePrescriptionForm.medication_qty');

            return;
        }

        if ($maxDosage > 0 && $qty > $maxDosage) {
            $unit = $this->ePrescriptionForm['medication_unit'] ?? '';
            $this->ePrescriptionWarningMessage = "Увага! За даним рецептом перевищено максимально допустиму кількість лікарського засобу [{$this->ePrescriptionSelectedProduct['name']}], що дозволена до виписування в 1 рецепті. Максимально допустима кількість ЛЗ становить {$maxDosage} {$unit}. Будь-ласка, поверніться та скоригуйте електронний рецепт!";
            $this->flashOutcome('error', $this->ePrescriptionWarningMessage);

            return;
        }

        if ($qty > $this->ePrescriptionRemainingQty && $this->ePrescriptionSelectedActivity['quantity'] !== null) {
            $this->ePrescriptionWarningMessage = "Кількість ЛЗ в рецепті ({$qty}) перевищує залишкову кількість у плані лікування ({$this->ePrescriptionRemainingQty}). Виписування неможливе.";
            $this->flashOutcome('error', $this->ePrescriptionWarningMessage);

            return;
        }

        if (!$this->ePrescriptionSkipTreatmentPeriod) {
            $lastActivePrescription = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('person_id', $this->carePlan->person_id)
                ->where('medication_id', $this->ePrescriptionForm['medication_id'])
                ->whereIn('status', ['active', 'signed'])
                ->orderBy('ended_at', 'desc')
                ->first();

            if ($lastActivePrescription && $lastActivePrescription->endedAt) {
                $lastEnd = \Carbon\Carbon::parse($lastActivePrescription->endedAt);
                $today = now();
                $remainingDays = $today->diffInDays($lastEnd, false);

                if ($remainingDays > 0) {
                    $prevDuration = $lastActivePrescription->startedAt ? \Carbon\Carbon::parse($lastActivePrescription->startedAt)->diffInDays($lastEnd) + 1 : 10;
                    $allowedDaysBeforeEnd = $prevDuration >= 21 ? 7 : 3;

                    if ($remainingDays > $allowedDaysBeforeEnd) {
                        $this->ePrescriptionWarningMessage = "Повторний Е-Рецепт на той же МНН можна виписати за {$allowedDaysBeforeEnd} днів до закінчення терміну лікування попереднього Е-Рецепту. Попередній рецепт діє до " . $lastEnd->format('d.m.Y') . " (залишилось {$remainingDays} днів).";
                        $this->flashOutcome('error', $this->ePrescriptionWarningMessage);

                        return;
                    }
                }
            }
        }

        $dailyDose = (float) $this->ePrescriptionForm['max_dose_per_period'];
        $drugName = (string) ($this->ePrescriptionSelectedProduct['name'] ?? 'ЛЗ');
        $unit = (string) ($this->ePrescriptionForm['medication_unit'] ?? 'од.');
        $maxDailyDosage = (float) ($this->ePrescriptionSelectedProduct['max_daily_dosage'] ?? 0);
        $recommendedDailyDose = (float) ($this->ePrescriptionSelectedProduct['daily_dosage'] ?? 0);
        $planDailyAmount = (float) ($this->ePrescriptionSelectedActivity['daily_amount'] ?? 0);

        if ($maxDailyDosage > 0 && $dailyDose > $maxDailyDosage) {
            $message = __('care-plan.eprescription_max_daily_dosage_exceeded', [
                'name' => $drugName,
                'max' => $maxDailyDosage,
                'unit' => $unit,
            ]);
            $this->ePrescriptionWarningMessage = $message;
            $this->flashOutcome('error', $message);

            return;
        }

        $exceededRecommended = $recommendedDailyDose > 0 && $dailyDose > $recommendedDailyDose;
        $exceededPlan = $planDailyAmount > 0 && $dailyDose > $planDailyAmount;

        if ($exceededRecommended || $exceededPlan) {
            $this->ePrescriptionShowDailyDoseWarning = true;
            $warning = $exceededPlan
                ? __('care-plan.eprescription_plan_daily_amount_warning', ['name' => $drugName])
                : __('care-plan.eprescription_daily_dose_warning', ['name' => $drugName]);
            $this->ePrescriptionWarningMessage = $warning;
            $this->flashOutcome('warning', $warning);

            return;
        }

        $this->submitEPrescriptionRequest();
    }

    public function submitEPrescriptionRequest(): void
    {
        try {
            $employeeContext = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)
                ->resolveEmployeeContext($this->carePlan, null, Auth::user()?->activeDoctorEmployee()?->id);
            $activity = $this->ownedActivity((int) $this->ePrescriptionForm['activity_id']);

            $uuid = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->createCarePlanDraft(
                $this->carePlan,
                $activity,
                $this->ePrescriptionForm,
                $employeeContext
            );

            $this->showEPrescriptionDrawer = false;
            $this->ePrescriptionRequestIdToSign = $uuid;
            $this->flashOutcome('success', 'Заявку на е-рецепт створено. Підпишіть КЕП.');
            $this->openSignatureModal('sign_eprescription');

        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->flashOutcome('error', $exception->getTranslatedMessage());
        } catch (\App\Exceptions\EHealth\EHealthResponseException $e) {
            if ($e->getCode() === 403 || $e->response->status() === 403) {
                Log::warning('CarePlanShow: 403 access denied when submitting ePrescription. Prompting for approval.');
                $this->flashOutcome('warning', 'Відсутній доступ до медичних даних. Будь ласка, надішліть запит на доступ пацієнту.');
                $this->openMethodSelectionModal();
            } else {
                Log::error('CarePlanShow: failed to create ePrescription API error: ' . $e->getMessage());
                $this->flashOutcome('error', 'Не вдалося створити заявку на рецепт: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to create ePrescription: ' . $e->getMessage());
            $this->flashOutcome('error', 'Не вдалося створити заявку на рецепт: ' . $e->getMessage());
        }
    }

    public function syncEPrescriptions(): void
    {
        try {
            $personUuid = $this->carePlan->person->uuid ?? null;
            if (!$personUuid) {
                $this->flashOutcome('error', 'Не знайдено ідентифікатор пацієнта в ЕСОЗ');

                return;
            }

            $activityUuids = $this->carePlan->activities->pluck('uuid')->toArray();
            $localRequests = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::whereHas('basedOn', fn($q) => $q->whereIn('value', $activityUuids))->get();

            if ($localRequests->isEmpty()) {
                $this->flashOutcome('info', 'Немає виписаних рецептів для синхронізації у цьому плані лікування');

                return;
            }

            $updatedCount = 0;

            // Check active/completed medication requests in eHealth
            $activeResponse = \App\Classes\eHealth\Api\MedicationRequest::getBySearchParams((string) $personUuid, []);
            $activeItems = $activeResponse['data'] ?? ($activeResponse[0] ?? []);

            if (is_array($activeItems)) {
                foreach ($activeItems as $item) {
                    if (empty($item['id'])) {
                        continue;
                    }
                    $match = $localRequests->firstWhere('uuid', $item['id'])
                        ?? (!empty($item['request_number']) ? $localRequests->firstWhere('request_number', $item['request_number']) : null);

                    if ($match) {
                        $payload = is_array($match->ehealth_payload) ? $match->ehealth_payload : [];
                        $needsUpdate = false;

                        if (!empty($item['status']) && strtolower((string) $item['status']) !== $match->status) {
                            $match->status = strtolower((string) $item['status']);
                            $needsUpdate = true;
                            $updatedCount++;
                        }

                        if ($item['id'] !== $match->uuid && ($payload['active_id'] ?? null) !== $item['id']) {
                            $payload['active_id'] = $item['id'];
                            $match->ehealth_payload = $payload;
                            $needsUpdate = true;
                        }

                        if ($needsUpdate) {
                            $match->save();
                        }
                    }
                }
            }

            // Check draft/rejected requests in eHealth
            $draftResponse = \App\Classes\eHealth\Api\MedicationRequest::getRequestsBySearchParams((string) $personUuid, []);
            $draftItems = $draftResponse['data'] ?? ($draftResponse[0] ?? []);

            if (is_array($draftItems)) {
                foreach ($draftItems as $item) {
                    if (empty($item['id'])) {
                        continue;
                    }
                    $match = $localRequests->firstWhere('uuid', $item['id'])
                        ?? (!empty($item['request_number']) ? $localRequests->firstWhere('request_number', $item['request_number']) : null);

                    if ($match && !in_array($match->status, ['active', 'completed', 'expired'], true) && !empty($item['status']) && strtolower((string) $item['status']) !== $match->status) {
                        $match->update(['status' => strtolower((string) $item['status'])]);
                        $updatedCount++;
                    }
                }
            }

            $this->refreshCarePlan();
            $this->flashOutcome('success', "Синхронізовано з ЕСОЗ. Оновлено статусів: {$updatedCount}");

        } catch (\Exception $e) {
            Log::error('ManagesCarePlanEPrescription sync error: ' . $e->getMessage());
            $this->flashOutcome('error', 'Помилка при синхронізації з ЕСОЗ: ' . $e->getMessage());
        }
    }

    public function signEPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            $this->flashOutcome('error', 'Не вибрано заявку на рецепт для підписання');
            $this->showSignatureModal = false;

            return;
        }

        $this->authorizeCarePlanWrite();

        $requestRecord = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForPerson((string) $this->ePrescriptionRequestIdToSign, (int) $this->carePlan->personId);

        try {
            $result = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->signPrescription(
                $this->carePlan,
                $requestRecord,
                array_merge($this->form, [
                    'request_notification_disabled' => filter_var(
                        $this->ePrescriptionSelectedProgram['settings']['request_notification_disabled'] ?? false,
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
                $this->flashOutcome('warning', $result['warning_message']);
            }

            $this->flashOutcome('success', $result['success_message']);
            $this->showSignatureModal = false;
            $this->refreshCarePlan();

        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign E-Prescription validation: ' . $translatedMsg);
            $this->flashOutcome('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to sign E-Prescription: ' . $e->getMessage());
            $this->flashOutcome('error', 'Помилка при підписанні рецепту: ' . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function rejectPrescription(string $requestId): void
    {
        $this->authorizeCarePlanWrite();

        $requestRecord = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForPerson($requestId, (int) $this->carePlan->personId);

        try {
            if (in_array(strtolower((string) $requestRecord->status), ['new', 'draft'], true)) {
                app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->rejectPrescription($this->carePlan, $requestRecord);
                $this->refreshCarePlan();
                $this->flashOutcome('success', 'Електронний рецепт успішно відхилено.');
            } else {
                $this->ePrescriptionRequestIdToSign = $requestId;
                $this->openSignatureModal('reject_prescription');
            }
        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            $this->flashOutcome('error', $translatedMsg);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            $this->flashOutcome('error', 'Не вдалося відхилити рецепт: ' . $e->getMessage());
        }
    }

    public function signRejectPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            $this->flashOutcome('error', 'Не вибрано рецепт для відхилення');
            $this->showSignatureModal = false;

            return;
        }

        $this->authorizeCarePlanWrite();

        $requestRecord = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForPerson((string) $this->ePrescriptionRequestIdToSign, (int) $this->carePlan->personId);

        try {
            app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->rejectPrescription(
                $this->carePlan,
                $requestRecord,
                array_merge($this->form, ['signer_tax_id' => Auth::user()?->party?->taxId]),
                $this->statusReason
            );

            $this->showSignatureModal = false;
            $this->refreshCarePlan();
            $this->flashOutcome('success', 'Електронний рецепт успішно відхилено.');

        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            $this->flashOutcome('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            $errorMsg = 'Не вдалося відхилити рецепт: ' . $e->getMessage();
            $this->flashOutcome('error', $errorMsg);
            $this->showSignatureModal = false;
        }
    }

    public function resendPrescriptionSms(string $prescriptionId): void
    {
        $this->authorizeCarePlanWrite();
        app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

        try {
            $response = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->resendSms($this->carePlan->person->uuid, $prescriptionId);
            if ($response->successful()) {
                $this->flashOutcome('success', 'СМС з кодом погашення успішно надіслано повторно пацієнту.');
            } else {
                $this->flashOutcome('error', 'Не вдалося повторно надіслати СМС: ' . json_encode($response->getData()));
            }
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to resend SMS: ' . $e->getMessage());
            $this->flashOutcome('error', 'Помилка надсилання СМС: ' . $e->getMessage());
        }
    }

    public function loadPrintoutForm(string $prescriptionId): string
    {
        app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

        try {
            $printout = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->fetchPrintoutFromEhealth(
                $this->carePlan->person->uuid,
                $prescriptionId
            );

            if (is_array($printout) && isset($printout['printout_form'])) {
                $printout = $printout['printout_form'];
            }

            if (is_string($printout) && (str_contains($printout, '<html') || str_contains($printout, '<div'))) {
                $this->printableContent = $printout;
                $this->dispatch('printoutLoaded');

                return $this->printableContent;
            }

            $ehealthData = is_array($printout) ? $printout : null;

            $this->printableContent = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->buildFallbackPrintoutHtml(
                $this->carePlan,
                $prescriptionId,
                $this->ePrescriptionForm['signature_text'] ?? null,
                $ehealthData,
                Auth::user()?->party?->full_name
            );
            $this->dispatch('printoutLoaded');

            return $this->printableContent;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load printout form: ' . $e->getMessage());
            $this->flashOutcome('error', 'Не вдалося завантажити форму пам’ятки.');

            return '<h3>Помилка при формуванні даних для друку: ' . htmlspecialchars($e->getMessage()) . '</h3>';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveDrugForActivity(\App\Models\CarePlanActivity $activity): ?array
    {
        if (empty($activity->productReference)) {
            return null;
        }

        $filters = ['innm_dosage_id' => $activity->productReference];
        if (!empty($activity->program)) {
            $filters['medical_program_id'] = $activity->program;
        }

        $data = EHealth::drug()->getMany($filters)->getData();
        if (!empty($data[0])) {
            return $data[0];
        }

        $fallback = EHealth::drug()->getMany(['innm_id' => $activity->productReference])->getData();

        return $fallback[0] ?? null;
    }

    protected function resolveMedicationPackageStep(array $drug): float
    {
        $packages = $drug['packages'] ?? [];
        if (!is_array($packages) || empty($packages)) {
            return 1.0;
        }

        $package = $packages[0];
        $minQty = (float) ($package['package_min_qty'] ?? 0);
        if ($minQty > 0) {
            return $minQty;
        }

        $packageQty = (float) ($package['package_qty'] ?? 0);

        return $packageQty > 0 ? $packageQty : 1.0;
    }

    protected function isMedicationQtyDivisible(float $qty, array $drug): bool
    {
        $step = $this->resolveMedicationPackageStep($drug);
        if ($step <= 0) {
            return true;
        }

        $quotient = $qty / $step;

        return abs($quotient - round($quotient)) < 1e-6;
    }

    /**
     * Livewire AJAX does not remount the layout toast, so pair session flash with a
     * flashMessage dispatch and scroll the drawer to the invalid field.
     */
    private function failEPrescriptionField(string $message, string $field): void
    {
        $this->ePrescriptionWarningMessage = $message;
        $this->addError($field, $message);
        $this->flashOutcome('error', $message);
        $this->dispatch('scroll-to-error');
    }

    public function blockPrescription(string $prescriptionId): void
    {
        $this->authorizeCarePlanWrite();

        $requestRecord = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

        try {
            app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->block($this->carePlan->person->uuid, $prescriptionId, [
                'status_reason' => 'Призупинення або блокування призначення',
            ]);
            $requestRecord->update(['status' => 'blocked']);
            $this->refreshCarePlan();
            $this->flashOutcome('success', 'Рецепт успішно заблоковано в ЕСОЗ.');
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to block prescription: ' . $e->getMessage());
            $this->flashOutcome('error', 'Помилка блокування рецепту: ' . $e->getMessage());
        }
    }

    public function unblockPrescription(string $prescriptionId): void
    {
        $this->authorizeCarePlanWrite();

        $requestRecord = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

        try {
            app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->unblock($this->carePlan->person->uuid, $prescriptionId, []);
            $requestRecord->update(['status' => 'active']);
            $this->refreshCarePlan();
            $this->flashOutcome('success', 'Рецепт успішно розблоковано в ЕСОЗ.');
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to unblock prescription: ' . $e->getMessage());
            $this->flashOutcome('error', 'Помилка розблокування рецепту: ' . $e->getMessage());
        }
    }

    public function checkDispenseHistory(string $prescriptionId): void
    {
        app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

        try {
            $dispenses = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->getDispenseHistory($this->carePlan->person->uuid, $prescriptionId);
            $items = $dispenses['data'] ?? ($dispenses[0] ?? []);

            if (empty($items) || !is_array($items)) {
                $this->flashOutcome('info', 'Погашень рецепту в аптеці наразі не виявлено (рецепт ще не відпущено).');

                return;
            }

            $count = count($items);
            $latestStatus = $items[0]['status'] ?? 'невідомо';
            $this->flashOutcome('success', "Знайдено {$count} записів відпуску ліків в аптеці. Останній статус: {$latestStatus}.");
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: check dispense history returned 404 or error: ' . $e->getMessage());
            if (str_contains($e->getMessage(), '404') || str_contains(strtolower($e->getMessage()), 'not found')) {
                $this->flashOutcome('info', 'Погашень (відпуску ліків) за цим рецептом в ЕСОЗ наразі не виявлено (аптеки ще не відпускали ліки за цим номером).');

                return;
            }
            Log::error('CarePlanShow: failed to check dispense history: ' . $e->getMessage());
            $this->flashOutcome('error', 'Не вдалося отримати історію погашень: ' . $e->getMessage());
        }
    }
}
