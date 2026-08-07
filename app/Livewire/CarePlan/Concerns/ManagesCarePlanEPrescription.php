<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\CarePlanActivityRepository;
use App\Services\MedicalEvents\CarePlanActivityEHealthGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

trait ManagesCarePlanEPrescription
{
    public function initEPrescriptionForm(int $activityId, CarePlanActivityRepository $activityRepository): void
    {
        $activity = $activityRepository->findById($activityId);
        if (!$activity) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Призначення не знайдено']);

            return;
        }

        $planStatus = strtolower(is_array($this->carePlan->status)
            ? ($this->carePlan->status['coding'][0]['code'] ?? ($this->carePlan->status['text'] ?? ''))
            : (string) $this->carePlan->status);

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        $blockedPlanStatuses = ['cancelled', 'completed', 'terminated', 'entered-in-error'];
        $blockedActivityStatuses = ['cancelled', 'completed'];

        if (in_array($planStatus, $blockedPlanStatuses)) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Виписування рецепту заборонено: план лікування завершено, скасовано або відмінено.']);

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Виписування рецепту заборонено: це призначення вже завершено або скасовано.']);

            return;
        }

        if ($activity->resolvedKind() !== 'medication_request') {
            $this->dispatch('flashMessage', [
                'type' => 'error',
                'message' => __('care-plan.eprescription_wrong_activity_kind'),
            ]);

            return;
        }

        try {
            app(CarePlanActivityEHealthGuard::class)->assertRegisteredInEHealth($this->carePlan, $activity);
        } catch (\RuntimeException $exception) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $exception->getMessage()]);

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
                'name' => $activity->product_reference,
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

        $this->ePrescriptionAuthMethods = [];
        try {
            $this->ePrescriptionAuthMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->getData();
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to fetch auth methods: ' . $e->getMessage());
            $this->ePrescriptionAuthMethods = [
                ['uuid' => 'offline-method-uuid', 'type' => 'OFFLINE', 'alias' => 'Документи']
            ];
        }

        $issuedQty = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('based_on_id', $activity->id)
            ->whereNotIn('status', \App\Repositories\MedicalEvents\MedicalEventsRequestStatuses::EXCLUDED_FROM_ISSUED_SUM)
            ->sum('medication_qty');

        $activityQty = $activity->quantity;
        $this->ePrescriptionRemainingQty = $activityQty === null
            ? 1.0
            : max(0.0, (float) $activityQty - (float) $issuedQty);

        try {
            $eHealthActivity = EHealth::carePlanActivity()->getDetails(
                (string) $this->carePlan->person->uuid,
                (string) $this->carePlan->uuid,
                (string) $activity->uuid
            )->getData();
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
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $message]);

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

        $this->ePrescriptionForm = [
            'activity_id' => $activity->id,
            'medication_id' => $activity->product_reference,
            'started_at' => now()->toDateString(),
            'duration' => 10,
            'ended_at' => '',
            'medication_qty' => $defaultQty,
            'medication_unit' => $unit,
            'signature_text' => '',
            'max_dose_per_period' => (float) $activity->daily_amount ?: 1.0,
            'max_dose_per_administration' => 1.0,
            'inform_with' => !empty($this->ePrescriptionAuthMethods) ? ($this->ePrescriptionAuthMethods[0]['uuid'] ?? '') : '',
            'container_dosage' => '',
            'program_id' => $activity->program,
            'note' => '',
            'route' => 'oral',
        ];

        $this->ePrescriptionShowDailyDoseWarning = false;
        $this->ePrescriptionShowRemainingQtyWarning = false;
        $this->ePrescriptionSelectedActivity = $activity->toArray();

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

        if (empty($this->ePrescriptionForm['inform_with'])) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Необхідно обрати метод автентифікації пацієнта']);

            return;
        }

        $qty = (float) $this->ePrescriptionForm['medication_qty'];
        $maxDosage = (float) ($this->ePrescriptionSelectedProduct['packages'][0]['max_request_dosage'] ?? ($this->ePrescriptionSelectedProduct['max_request_dosage'] ?? 0));
        $packageStep = $this->resolveMedicationPackageStep($this->ePrescriptionSelectedProduct ?? []);

        if ($packageStep > 0 && !$this->isMedicationQtyDivisible($qty, $this->ePrescriptionSelectedProduct ?? [])) {
            $message = __('care-plan.medication_qty_packaging', ['count' => $packageStep]);
            $this->ePrescriptionWarningMessage = $message;
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $message]);

            return;
        }

        if ($maxDosage > 0 && $qty > $maxDosage) {
            $unit = $this->ePrescriptionForm['medication_unit'] ?? '';
            $this->ePrescriptionWarningMessage = "Увага! За даним рецептом перевищено максимально допустиму кількість лікарського засобу [{$this->ePrescriptionSelectedProduct['name']}], що дозволена до виписування в 1 рецепті. Максимально допустима кількість ЛЗ становить {$maxDosage} {$unit}. Будь-ласка, поверніться та скоригуйте електронний рецепт!";
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $this->ePrescriptionWarningMessage]);

            return;
        }

        if ($qty > $this->ePrescriptionRemainingQty && $this->ePrescriptionSelectedActivity['quantity'] !== null) {
            $this->ePrescriptionWarningMessage = "Кількість ЛЗ в рецепті ({$qty}) перевищує залишкову кількість у плані лікування ({$this->ePrescriptionRemainingQty}). Виписування неможливе.";
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $this->ePrescriptionWarningMessage]);

            return;
        }

        if (!$this->ePrescriptionSkipTreatmentPeriod) {
            $lastActivePrescription = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('person_id', $this->carePlan->person_id)
                ->where('medication_id', $this->ePrescriptionForm['medication_id'])
                ->whereIn('status', ['active', 'signed'])
                ->orderBy('ended_at', 'desc')
                ->first();

            if ($lastActivePrescription && $lastActivePrescription->ended_at) {
                $lastEnd = \Carbon\Carbon::parse($lastActivePrescription->ended_at);
                $today = now();
                $remainingDays = $today->diffInDays($lastEnd, false);

                if ($remainingDays > 0) {
                    $prevDuration = $lastActivePrescription->started_at ? \Carbon\Carbon::parse($lastActivePrescription->started_at)->diffInDays($lastEnd) + 1 : 10;
                    $allowedDaysBeforeEnd = $prevDuration >= 21 ? 7 : 3;

                    if ($remainingDays > $allowedDaysBeforeEnd) {
                        $this->ePrescriptionWarningMessage = "Повторний Е-Рецепт на той же МНН можна виписати за {$allowedDaysBeforeEnd} днів до закінчення терміну лікування попереднього Е-Рецепту. Попередній рецепт діє до " . $lastEnd->format('d.m.Y') . " (залишилось {$remainingDays} днів).";
                        $this->dispatch('flashMessage', ['type' => 'error', 'message' => $this->ePrescriptionWarningMessage]);

                        return;
                    }
                }
            }
        }

        $dailyDose = (float) $this->ePrescriptionForm['max_dose_per_period'];
        $recommendedDailyDose = (float) ($this->ePrescriptionSelectedProduct['daily_dosage'] ?? 0);
        $planDailyAmount = (float) ($this->ePrescriptionSelectedActivity['daily_amount'] ?? 0);

        $exceededRecommended = $recommendedDailyDose > 0 && $dailyDose > $recommendedDailyDose;
        $exceededPlan = $planDailyAmount > 0 && $dailyDose > $planDailyAmount;

        if ($exceededRecommended || $exceededPlan) {
            $this->ePrescriptionShowDailyDoseWarning = true;
            $this->dispatch('flashMessage', ['type' => 'warning', 'message' => 'Перевищено добову дозу лікарського засобу! Будь ласка, перевірте попередження та підтвердіть виписування.']);

            return;
        }

        $this->submitEPrescriptionRequest();
    }

    public function submitEPrescriptionRequest(): void
    {
        try {
            $employeeContext = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->resolveEmployeeContext($this->carePlan);
            $activity = \App\Models\CarePlanActivity::find($this->ePrescriptionForm['activity_id']);

            $uuid = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->createDraft(
                $this->carePlan,
                $activity,
                $this->ePrescriptionForm,
                $employeeContext
            );

            $this->showEPrescriptionDrawer = false;
            $this->ePrescriptionRequestIdToSign = $uuid;
            $this->openSignatureModal('sign_eprescription');

        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->dispatch('flashMessage', [
                'type' => 'error',
                'message' => $exception->getTranslatedMessage(),
            ]);
        } catch (\App\Exceptions\EHealth\EHealthResponseException $e) {
            if ($e->getCode() === 403 || $e->response->status() === 403) {
                Log::warning('CarePlanShow: 403 access denied when submitting ePrescription. Prompting for approval.');
                $this->dispatch('flashMessage', ['type' => 'warning', 'message' => 'Відсутній доступ до медичних даних. Будь ласка, надішліть запит на доступ пацієнту.']);
                $this->openMethodSelectionModal();
            } else {
                Log::error('CarePlanShow: failed to create ePrescription API error: ' . $e->getMessage());
                $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося створити заявку на рецепт: ' . $e->getMessage()]);
            }
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to create ePrescription: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося створити заявку на рецепт: ' . $e->getMessage()]);
        }
    }

    public function syncEPrescriptions(): void
    {
        try {
            $personUuid = $this->carePlan->person->uuid ?? null;
            if (!$personUuid) {
                $this->dispatch('flashMessage', ['message' => 'Не знайдено ідентифікатор пацієнта в ЕСОЗ', 'type' => 'error']);

                return;
            }

            $activityIds = $this->carePlan->activities->pluck('id')->toArray();
            $localRequests = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::whereIn('based_on_id', $activityIds)->get();

            if ($localRequests->isEmpty()) {
                Session::flash('info', 'Немає виписаних рецептів для синхронізації у цьому плані лікування');

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
            $this->dispatch('flashMessage', ['message' => "Синхронізовано з ЕСОЗ. Оновлено статусів: {$updatedCount}", 'type' => 'success']);

        } catch (\Exception $e) {
            Log::error('ManagesCarePlanEPrescription sync error: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['message' => 'Помилка при синхронізації з ЕСОЗ: ' . $e->getMessage(), 'type' => 'error']);
        }
    }

    public function signEPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            $this->dispatch('flashMessage', ['message' => 'Не вибрано заявку на рецепт для підписання', 'type' => 'error']);
            $this->showSignatureModal = false;

            return;
        }

        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $this->ePrescriptionRequestIdToSign)->first();
        if (!$requestRecord) {
            $this->dispatch('flashMessage', ['message' => 'Заявку на рецепт не знайдено', 'type' => 'error']);
            $this->showSignatureModal = false;

            return;
        }

        try {
            $result = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->sign(
                $this->carePlan,
                $requestRecord,
                $this->form,
                $requestRecord->inform_with ?? '',
                $this->ePrescriptionRemainingQty
            );

            if (!empty($result['warning_message'])) {
                $this->dispatch('flashMessage', [
                    'type' => 'warning',
                    'message' => $result['warning_message']
                ]);
            }

            $this->dispatch('flashMessage', ['message' => $result['success_message'], 'type' => 'success']);
            $this->showSignatureModal = false;
            $this->refreshCarePlan();

        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign E-Prescription validation: ' . $translatedMsg);
            $this->dispatch('flashMessage', ['message' => $translatedMsg, 'type' => 'error']);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to sign E-Prescription: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['message' => 'Помилка при підписанні рецепту: ' . $e->getMessage(), 'type' => 'error']);
            $this->showSignatureModal = false;
        }
    }

    public function rejectPrescription(string $requestId): void
    {
        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $requestId)->first();
        if (!$requestRecord) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Рецепт не знайдено']);

            return;
        }

        try {
            if (in_array(strtolower((string) $requestRecord->status), ['new', 'draft'], true)) {
                app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->reject($this->carePlan, $requestRecord);
                $this->refreshCarePlan();
                $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Електронний рецепт успішно відхилено.']);
            } else {
                $this->ePrescriptionRequestIdToSign = $requestId;
                $this->openSignatureModal('reject_prescription');
            }
        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $translatedMsg]);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося відхилити рецепт: ' . $e->getMessage()]);
        }
    }

    public function signRejectPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            $this->dispatch('flashMessage', ['message' => 'Не вибрано рецепт для відхилення', 'type' => 'error']);
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вибрано рецепт для відхилення']);
            $this->showSignatureModal = false;

            return;
        }

        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $this->ePrescriptionRequestIdToSign)->first();
        if (!$requestRecord) {
            $this->dispatch('flashMessage', ['message' => 'Рецепт не знайдено', 'type' => 'error']);
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Рецепт не знайдено']);
            $this->showSignatureModal = false;

            return;
        }

        try {
            app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->reject(
                $this->carePlan,
                $requestRecord,
                $this->form,
                $this->statusReason
            );

            $this->showSignatureModal = false;
            $this->refreshCarePlan();
            $this->dispatch('flashMessage', ['message' => 'Електронний рецепт успішно відхилено.', 'type' => 'success']);
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Електронний рецепт успішно відхилено.']);

        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            $this->dispatch('flashMessage', ['message' => $translatedMsg, 'type' => 'error']);
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $translatedMsg]);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            $errorMsg = 'Не вдалося відхилити рецепт: ' . $e->getMessage();
            $this->dispatch('flashMessage', ['message' => $errorMsg, 'type' => 'error']);
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $errorMsg]);
            $this->showSignatureModal = false;
        }
    }

    public function resendPrescriptionSms(string $prescriptionId): void
    {
        try {
            $response = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->resendSms($this->carePlan->person->uuid, $prescriptionId);
            if ($response->successful()) {
                $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'СМС з кодом погашення успішно надіслано повторно пацієнту.']);
            } else {
                $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося повторно надіслати СМС: ' . json_encode($response->getData())]);
            }
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to resend SMS: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Помилка надсилання СМС: ' . $e->getMessage()]);
        }
    }

    public function loadPrintoutForm(string $prescriptionId): string
    {
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
                $ehealthData
            );
            $this->dispatch('printoutLoaded');

            return $this->printableContent;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load printout form: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося завантажити форму пам’ятки.']);

            return '<h3>Помилка при формуванні даних для друку: ' . htmlspecialchars($e->getMessage()) . '</h3>';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveDrugForActivity(\App\Models\CarePlanActivity $activity): ?array
    {
        if (empty($activity->product_reference)) {
            return null;
        }

        $filters = ['innm_dosage_id' => $activity->product_reference];
        if (!empty($activity->program)) {
            $filters['medical_program_id'] = $activity->program;
        }

        $data = EHealth::drug()->getMany($filters)->getData();
        if (!empty($data[0])) {
            return $data[0];
        }

        $fallback = EHealth::drug()->getMany(['innm_id' => $activity->product_reference])->getData();

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

    public function blockPrescription(string $prescriptionId): void
    {
        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $prescriptionId)->first();
        if (!$requestRecord) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Рецепт не знайдено']);

            return;
        }

        try {
            app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->block($this->carePlan->person->uuid, $prescriptionId, [
                'status_reason' => 'Призупинення або блокування призначення',
            ]);
            $requestRecord->update(['status' => 'blocked']);
            $this->refreshCarePlan();
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Рецепт успішно заблоковано в ЕСОЗ.']);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to block prescription: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Помилка блокування рецепту: ' . $e->getMessage()]);
        }
    }

    public function unblockPrescription(string $prescriptionId): void
    {
        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $prescriptionId)->first();
        if (!$requestRecord) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Рецепт не знайдено']);

            return;
        }

        try {
            app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->unblock($this->carePlan->person->uuid, $prescriptionId, []);
            $requestRecord->update(['status' => 'active']);
            $this->refreshCarePlan();
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Рецепт успішно розблоковано в ЕСОЗ.']);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to unblock prescription: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Помилка розблокування рецепту: ' . $e->getMessage()]);
        }
    }

    public function checkDispenseHistory(string $prescriptionId): void
    {
        try {
            $dispenses = app(\App\Services\MedicalEvents\MedicationRequestLifecycleService::class)->getDispenseHistory($this->carePlan->person->uuid, $prescriptionId);
            $items = $dispenses['data'] ?? ($dispenses[0] ?? []);

            if (empty($items) || !is_array($items)) {
                $this->dispatch('flashMessage', ['type' => 'info', 'message' => 'Погашень рецепту в аптеці наразі не виявлено (рецепт ще не відпущено).']);

                return;
            }

            $count = count($items);
            $latestStatus = $items[0]['status'] ?? 'невідомо';
            $this->dispatch('flashMessage', [
                'type' => 'success',
                'message' => "Знайдено {$count} записів відпуску ліків в аптеці. Останній статус: {$latestStatus}.",
            ]);
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: check dispense history returned 404 or error: ' . $e->getMessage());
            if (str_contains($e->getMessage(), '404') || str_contains(strtolower($e->getMessage()), 'not found')) {
                $this->dispatch('flashMessage', ['type' => 'info', 'message' => 'Погашень (відпуску ліків) за цим рецептом в ЕСОЗ наразі не виявлено (аптеки ще не відпускали ліки за цим номером).']);

                return;
            }
            Log::error('CarePlanShow: failed to check dispense history: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося отримати історію погашень: ' . $e->getMessage()]);
        }
    }
}
