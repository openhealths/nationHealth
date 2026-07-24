<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\CarePlanActivityRepository;
use App\Services\MedicalEvents\CarePlanActivityEHealthGuard;
use App\Services\MedicalEvents\EHealthJobResolver;
use Illuminate\Support\Facades\Auth;
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
            'started_at' => now()->format('d.m.Y'),
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
        ];

        $this->ePrescriptionShowDailyDoseWarning = false;
        $this->ePrescriptionShowRemainingQtyWarning = false;
        $this->ePrescriptionSelectedActivity = $activity->toArray();

        $this->calculateTreatmentDates();
        $this->showEPrescriptionDrawer = true;
    }

    public function updatedEPrescriptionForm($value, $name): void
    {
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
            $start = \Carbon\Carbon::createFromFormat('d.m.Y', $this->ePrescriptionForm['started_at']);
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
            $this->ePrescriptionForm['ended_at'] = $end->format('d.m.Y');
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

            return;
        }

        if ($qty > $this->ePrescriptionRemainingQty && $this->ePrescriptionSelectedActivity['quantity'] !== null) {
            $this->ePrescriptionWarningMessage = "Кількість ЛЗ в рецепті ({$qty}) перевищує залишкову кількість у плані лікування ({$this->ePrescriptionRemainingQty}). Виписування неможливе.";

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

            return;
        }

        $this->submitEPrescriptionRequest();
    }

    public function submitEPrescriptionRequest(): void
    {
        try {
            $employee = Auth::user()?->activeDoctorEmployee();
            $uuids = [
                'person_uuid' => $this->carePlan->person->uuid,
                'encounter_uuid' => $this->carePlan->encounter?->uuid ?? null,
                'employee_uuid' => $employee?->uuid,
                'legal_entity_uuid' => $employee?->legalEntity?->uuid,
                'division_uuid' => $employee?->division?->uuid,
            ];

            $dbData = [
                'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                'status' => 'draft',
                'intent' => 'order',
                'medication_id' => $this->ePrescriptionForm['medication_id'],
                'medication_qty' => (float) $this->ePrescriptionForm['medication_qty'],
                'medication_program_id' => $this->ePrescriptionForm['program_id'] ?: null,
                'based_on_uuid' => $this->ePrescriptionSelectedActivity['uuid'],
                'note' => $this->ePrescriptionForm['signature_text'],
                'dosage_instructions' => [
                    [
                        'sequence' => 1,
                        'text' => $this->ePrescriptionForm['signature_text'],
                        'as_needed_boolean' => false,
                        'route' => '26643006',
                        'dose_and_rate' => [
                            [
                                'dose_quantity_value' => (float) $this->ePrescriptionForm['max_dose_per_administration'],
                                'dose_quantity_unit' => $this->ePrescriptionForm['medication_unit']
                            ]
                        ],
                        'max_dose_per_period' => $this->ePrescriptionForm['max_dose_per_period'],
                        'max_dose_per_administration' => $this->ePrescriptionForm['max_dose_per_administration'],
                    ]
                ],
                'started_at' => convertToYmd($this->ePrescriptionForm['started_at']),
                'ended_at' => convertToYmd($this->ePrescriptionForm['ended_at']),
                'inform_with' => $this->ePrescriptionForm['inform_with'],
            ];

            $mapper = new \App\Services\MedicalEvents\Mappers\MedicationRequestMapper();
            $apiPayload = $mapper->toCreateRequestPayload($dbData, $uuids, $this->carePlan->uuid);

            $prequalifyResponse = EHealth::medicationRequest()->prequalify($apiPayload);
            app(\App\Services\MedicalEvents\EHealthJobResolver::class)->assertPrequalifyValid($prequalifyResponse->getData());

            $response = EHealth::medicationRequest()->createRequest($apiPayload);
            $responseData = $response->getData();

            $dbData['employee_id'] = Auth::user()?->activeDoctorEmployee()?->id;
            $dbData['division_id'] = Auth::user()?->activeDoctorEmployee()?->division_id ?? null;
            $dbData['based_on_id'] = $this->ePrescriptionSelectedActivity['id'];
            $dbData['context_id'] = $this->carePlan->encounter?->id ?? null;
            $dbData['request_number'] = $responseData['request_number'] ?? null;
            $dbData['status'] = $responseData['status'] ?? 'NEW';
            $dbData['uuid'] = $responseData['id'] ?? $dbData['uuid'];

            \App\Repositories\MedicalEvents\Repository::medicationRequest()->store($dbData, $this->carePlan->person_id);

            $this->showEPrescriptionDrawer = false;
            $this->ePrescriptionRequestIdToSign = $dbData['uuid'];
            $this->openSignatureModal('sign_eprescription');

        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->dispatch('flashMessage', [
                'type' => 'error',
                'message' => $exception->getTranslatedMessage(),
            ]);
        } catch (EHealthResponseException $e) {
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

    public function signEPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            Session::flash('error', 'Не вибрано заявку на рецепт для підписання');
            $this->showSignatureModal = false;

            return;
        }

        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $this->ePrescriptionRequestIdToSign)->first();
        if (!$requestRecord) {
            Session::flash('error', 'Заявку на рецепт не знайдено');
            $this->showSignatureModal = false;

            return;
        }

        try {
            $uuids = [
                'person_uuid' => $this->carePlan->person->uuid,
                'encounter_uuid' => $this->carePlan->encounter?->uuid ?? null,
                'employee_uuid' => Auth::user()?->activeDoctorEmployee()?->uuid,
                'legal_entity_uuid' => Auth::user()?->activeDoctorEmployee()?->legalEntity?->uuid,
            ];

            $dbData = $requestRecord->toArray();
            $dbData['dosage_instructions'] = $requestRecord->dosageInstructions()->get()->toArray();

            $dbData['dosage_instructions'] = array_map(function ($inst) {
                if (is_string($inst['timing'])) {
                    $inst['timing'] = json_decode($inst['timing'], true);
                }
                if (is_string($inst['dose_and_rate'])) {
                    $inst['dose_and_rate'] = json_decode($inst['dose_and_rate'], true);
                }

                return $inst;
            }, $dbData['dosage_instructions']);

            $activity = \App\Models\CarePlanActivity::find($requestRecord->based_on_id);
            $dbData['based_on_uuid'] = $activity?->uuid;

            $mapper = new \App\Services\MedicalEvents\Mappers\MedicationRequestMapper();
            $fhirPayload = $mapper->toFhir($dbData, $uuids);

            $informWithVal = $requestRecord->inform_with ?? '';
            $informWithId = explode('|', $informWithVal)[0] ?? '';
            $fhirPayload['inform_with'] = [
                'identifier' => [
                    'value' => $informWithId
                ]
            ];

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($fhirPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $eHealthResponse = EHealth::medicationRequest()->signRequest(
                $requestRecord->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = app(EHealthJobResolver::class)->resolve($responseData);

            if (in_array(strtolower((string) ($finalResponse['status'] ?? '')), ['failed', 'error'], true)) {
                throw new EHealthValidationException($finalResponse);
            }

            $entity = isset($finalResponse['result'][0]) ? ($finalResponse['result'][0] ?? $finalResponse['result']) : $finalResponse;
            $requestNumber = $entity['request_number'] ?? $requestRecord->request_number;
            $finalStatus = $entity['status'] ?? 'active';

            $requestRecord->update([
                'status' => $finalStatus,
                'request_number' => $requestNumber,
            ]);

            // Update CarePlanActivity status to 'in-progress' if it is 'scheduled'
            if ($activity && $activity->status === 'scheduled') {
                $activity->update(['status' => 'in-progress']);
            }

            $remainingQty = $this->ePrescriptionRemainingQty - $requestRecord->medication_qty;
            if ($remainingQty < $requestRecord->medication_qty) {
                $this->dispatch('flashMessage', [
                    'type' => 'warning',
                    'message' => "Увага! Для пацієнта в плані лікування залишалось лікарського засобу в кількості " . $this->ePrescriptionRemainingQty . " " . ($dbData['dosage_instructions'][0]['dose_and_rate'][0]['dose_quantity_unit'] ?? '') . ". Повідомте пацієнту, що для подальшого отримання ліків необхідно звернутись до лікаря для коригування плану."
                ]);
            }

            $authMethodName = explode('|', $informWithVal)[1] ?? 'OTP';
            $phoneNumber = explode('|', $informWithVal)[2] ?? '';

            if (in_array(strtolower((string) $finalStatus), ['pending', 'processing'], true)) {
                $successMsg = 'Запит на е-рецепт прийнято в обробку ЕСОЗ. Фінальний статус та номер рецепта з’являться після завершення асинхронної задачі.';
            } elseif (strtoupper($authMethodName) === 'OTP' || strtoupper($authMethodName) === 'THIRD_PERSON') {
                $successMsg = "Електронний рецепт № {$requestNumber} створено в електронній системі охорони здоров’я. Номер рецепта та код погашення надіслано в СМС-повідомленні на номер {$phoneNumber}. Не забудьте попередити про це пацієнта! При необхідності роздрукуйте інформаційну пам’ятку пацієнту.";
            } else {
                $successMsg = "Електронний рецепт № {$requestNumber} створено в електронній системі охорони здоров’я. Код погашення зазначено в друкованій інформаційній пам’ятці. Не забудьте повідомити дані пацієнту та обов`язково роздрукувати інформаційну пам’ятку з кодом погашення!";
            }

            Session::flash('success', $successMsg);
            $this->showSignatureModal = false;
            $this->refreshCarePlan();

        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign E-Prescription validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to sign E-Prescription: ' . $e->getMessage());
            Session::flash('error', 'Помилка при підписанні рецепту: ' . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function cancelPrescription(string $requestId): void
    {
        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $requestId)->first();
        if (!$requestRecord) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Рецепт не знайдено']);

            return;
        }

        $this->openSignatureModal('cancel_prescription', null, $requestId);
    }

    public function signCancelPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            Session::flash('error', 'Не вибрано рецепт для скасування');
            $this->showSignatureModal = false;

            return;
        }

        $requestRecord = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::where('uuid', $this->ePrescriptionRequestIdToSign)->first();
        if (!$requestRecord) {
            Session::flash('error', 'Рецепт не знайдено');
            $this->showSignatureModal = false;

            return;
        }

        try {
            $payload = [
                'status_reason' => [
                    'coding' => [
                        [
                            'system' => 'eHealth/care_plan_cancel_reasons',
                            'code' => $this->statusReason ?: 'entered-in-error'
                        ]
                    ]
                ]
            ];

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $response = EHealth::medicationRequest()->cancel($this->carePlan->person->uuid, $requestRecord->uuid, [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
                'status_reason' => $payload['status_reason'],
            ]);

            if ($response->successful()) {
                $requestRecord->update(['status' => 'cancelled']);
                $this->showSignatureModal = false;
                $this->refreshCarePlan();
                $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Електронний рецепт успішно скасовано.']);
            } else {
                throw new \Exception(json_encode($response->getData()));
            }
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to cancel prescription validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to cancel prescription: ' . $e->getMessage());
            Session::flash('error', 'Не вдалося скасувати рецепт: ' . $e->getMessage());
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
            if (strtolower((string) $requestRecord->status) === 'new') {
                EHealth::medicationRequest()->rejectRequest($requestId);
            } else {
                EHealth::medicationRequest()->reject($this->carePlan->person->uuid, $requestId, [
                    'reject_reason_code' => 'patient_left_the_program'
                ]);
            }

            $requestRecord->update(['status' => 'rejected']);
            $this->refreshCarePlan();
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Електронний рецепт успішно відхилено.']);
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $translatedMsg]);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося відхилити рецепт: ' . $e->getMessage()]);
        }
    }

    public function resendPrescriptionSms(string $prescriptionId): void
    {
        try {
            $response = $this->medicationLifecycle->resendSms($this->carePlan->person->uuid, $prescriptionId);
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

    public function loadPrintoutForm(string $prescriptionId): void
    {
        try {
            $printout = $this->medicationLifecycle->fetchPrintoutFromEhealth(
                $this->carePlan->person->uuid,
                $prescriptionId
            );

            if (!empty($printout)) {
                $this->printableContent = $printout;
                $this->dispatch('printoutLoaded');

                return;
            }

            $this->printableContent = $this->medicationLifecycle->buildFallbackPrintoutHtml(
                $this->carePlan,
                $prescriptionId,
                $this->ePrescriptionForm['signature_text'] ?? null
            );
            $this->dispatch('printoutLoaded');
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load printout form: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося завантажити форму пам’ятки.']);
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
}
