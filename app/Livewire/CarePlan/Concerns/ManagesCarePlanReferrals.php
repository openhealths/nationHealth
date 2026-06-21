<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

trait ManagesCarePlanReferrals
{
    public function initReferralForm(int $activityId, CarePlanActivityRepository $activityRepository): void
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
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Виписування направлення заборонено: план лікування завершено, скасовано або відмінено.']);

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Виписування направлення заборонено: це призначення вже завершено або скасовано.']);

            return;
        }

        $this->referralWarningMessage = '';

        // Calculate remaining quantity
        $activityQty = (float) $activity->quantity;
        $issuedQty = $this->referralLifecycle->sumIssuedQuantity($activity);
        $this->referralRemainingQty = max(0.0, $activityQty - $issuedQty);

        $code = $activity->product_codeable_concept ?? $activity->product_reference ?? 'од.';

        $supportingInfo = [];
        $activity->reasonReferences()->get()->each(function ($identifier) use (&$supportingInfo) {
            $typeCode = $identifier->type->first()?->coding?->first()?->code ?? 'condition';
            $supportingInfo[] = [
                'type' => $typeCode,
                'uuid' => $identifier->value
            ];
        });

        $this->referralForm = [
            'activity_id' => $activity->id,
            'kind' => $activity->kind,
            'code' => $code,
            'quantity' => min($this->referralRemainingQty, 1.0),
            'started_at' => $activity->scheduled_period_start ? $activity->scheduled_period_start->format('d.m.Y') : now()->format('d.m.Y'),
            'ended_at' => $activity->scheduled_period_end ? $activity->scheduled_period_end->format('d.m.Y') : now()->addMonths(3)->format('d.m.Y'),
            'priority' => 'routine',
            'intent' => 'order',
            'category' => $activity->kind === 'service_request' ? 'procedure' : null,
            'note' => '',
            'program_id' => $activity->program ?? null,
            'supporting_info' => $supportingInfo
        ];

        $this->referralShowRemainingQtyWarning = false;
        $this->referralSelectedActivity = $activity->toArray();
        $this->showReferralDrawer = true;
    }

    public function validateReferral(): void
    {
        $this->referralWarningMessage = '';
        $this->referralShowRemainingQtyWarning = false;

        $rules = [
            'referralForm.started_at' => 'required|date_format:d.m.Y',
            'referralForm.ended_at' => 'required|date_format:d.m.Y|after_or_equal:referralForm.started_at',
            'referralForm.quantity' => 'required|numeric|min:0.01',
            'referralForm.priority' => 'required|in:routine,urgent,asap,stat',
        ];

        if ($this->referralForm['kind'] === 'service_request') {
            $rules['referralForm.category'] = 'required|string';
        }

        $this->validate($rules);

        $qty = (float) $this->referralForm['quantity'];
        if ($qty > $this->referralRemainingQty) {
            $this->referralShowRemainingQtyWarning = true;
            $this->referralWarningMessage = 'Кількість перевищує залишок за призначенням (' . $this->referralRemainingQty . ')';
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Кількість перевищує залишок за призначенням.']);

            return;
        }

        // Propose to sign
        $this->showReferralDrawer = false;

        try {
            $employee = Auth::user()?->activeDoctorEmployee();
            $this->referralRequestIdToSign = $this->referralLifecycle->createDraft(
                $this->carePlan,
                $this->referralForm,
                $qty,
                [
                    'employee_id' => $employee?->id,
                    'division_id' => $employee?->division_id,
                    'employee_uuid' => $employee?->uuid,
                    'legal_entity_uuid' => $employee?->legalEntity?->uuid,
                ]
            );
            $signAction = $this->referralForm['kind'] === 'service_request'
                ? 'sign_servicerequest'
                : 'sign_devicerequest';
            $this->openSignatureModal($signAction);
        } catch (EHealthValidationException $exception) {
            $this->showReferralDrawer = true;
            Session::flash('error', $exception->getTranslatedMessage());
        } catch (\Exception $exception) {
            $this->showReferralDrawer = true;
            Log::error('CarePlanShow: failed to create referral request: ' . $exception->getMessage());
            Session::flash('error', 'Не вдалося створити заявку на направлення: ' . $exception->getMessage());
        }
    }

    public function resendReferralSms(string $requestId, string $kind): void
    {
        try {
            $response = $this->referralLifecycle->resendSms($this->carePlan->person->uuid, $requestId, $kind);

            if ($response->successful()) {
                $this->dispatch('flashMessage', [
                    'type' => 'success',
                    'message' => 'СМС з кодом підтвердження успішно надіслано повторно пацієнту.',
                ]);

                return;
            }

            $this->dispatch('flashMessage', [
                'type' => 'error',
                'message' => 'Не вдалося повторно надіслати СМС: ' . json_encode($response->getData()),
            ]);
        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS validation: ' . $exception->getTranslatedMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $exception->getTranslatedMessage()]);
        } catch (\Exception $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Помилка надсилання СМС: ' . $exception->getMessage()]);
        }
    }

    public function signReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            Session::flash('error', 'Не вибрано направлення для підписання');
            $this->showSignatureModal = false;

            return;
        }

        $kind = $this->actionType === 'sign_servicerequest' ? 'service_request' : 'device_request';

        if ($kind === 'service_request') {
            $requestRecord = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::where('uuid', $this->referralRequestIdToSign)->first();
        } else {
            $requestRecord = \App\Models\MedicalEvents\Sql\DeviceRequestRequest::where('uuid', $this->referralRequestIdToSign)->first();
        }

        if (!$requestRecord) {
            Session::flash('error', 'Направлення не знайдено');
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
            $activity = \App\Models\CarePlanActivity::find($requestRecord->based_on_id);
            $dbData['based_on_uuid'] = $activity?->uuid;

            if ($kind === 'service_request') {
                $mapper = new \App\Services\MedicalEvents\Mappers\ServiceRequestMapper();
            } else {
                $mapper = new \App\Services\MedicalEvents\Mappers\DeviceRequestMapper();
            }

            $fhirPayload = $mapper->toFhir($dbData, $uuids);

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($fhirPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            if ($kind === 'service_request') {
                $eHealthResponse = EHealth::serviceRequest()->signRequest(
                    $this->carePlan->person->uuid,
                    $requestRecord->uuid,
                    [
                        'signed_data' => $signedContent,
                        'signed_data_encoding' => 'base64',
                    ]
                );
            } else {
                $eHealthResponse = EHealth::deviceRequest()->signRequest(
                    $this->carePlan->person->uuid,
                    $requestRecord->uuid,
                    [
                        'signed_data' => $signedContent,
                        'signed_data_encoding' => 'base64',
                    ]
                );
            }

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = EHealth::job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while (in_array($finalResponse['status'] ?? '', ['PENDING', 'PROCESSING']) && $attempts < 15);
            }

            if (($finalResponse['status'] ?? '') === 'ERROR') {
                throw new \Exception($finalResponse['error']['message'] ?? 'Помилка обробки запиту в eHealth');
            }

            $dbData['status'] = $finalResponse['status'] ?? 'active';
            $dbData['request_number'] = $finalResponse['request_number'] ?? ($finalResponse['requisition'] ?? null);
            $dbData['uuid'] = $finalResponse['id'] ?? $dbData['uuid'];

            if ($kind === 'service_request') {
                \App\Repositories\MedicalEvents\Repository::serviceRequest()->store($dbData, $this->carePlan->person_id);
            } else {
                \App\Repositories\MedicalEvents\Repository::deviceRequest()->store($dbData, $this->carePlan->person_id);
            }

            // Update CarePlanActivity status to 'in-progress' if it is 'scheduled'
            if ($activity && $activity->status === 'scheduled') {
                $activity->update(['status' => 'in-progress']);
            }

            $this->showSignatureModal = false;
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Направлення успішно створено та підписано в eHealth.']);
            $this->refreshCarePlan();

        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign referral validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to sign referral: ' . $e->getMessage());
            Session::flash('error', 'Не вдалося підписати направлення: ' . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function cancelReferral(string $requestId, string $kind): void
    {
        $this->openSignatureModal('cancel_referral', null, $requestId);
    }

    public function signCancelReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            Session::flash('error', 'Не вибрано направлення для скасування');
            $this->showSignatureModal = false;

            return;
        }

        $service = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::where('uuid', $this->referralRequestIdToSign)->first();
        $device = null;
        if (!$service) {
            $device = \App\Models\MedicalEvents\Sql\DeviceRequestRequest::where('uuid', $this->referralRequestIdToSign)->first();
        }

        $record = $service ?: $device;
        if (!$record) {
            Session::flash('error', 'Направлення не знайдено');
            $this->showSignatureModal = false;

            return;
        }

        $kind = $service ? 'service_request' : 'device_request';

        try {
            $payload = [
                'status_reason' => $this->statusReason ?: 'entered-in-error'
            ];

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            if ($kind === 'service_request') {
                $response = EHealth::serviceRequest()->cancel($this->carePlan->person->uuid, $record->uuid, [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $payload['status_reason'],
                ]);
            } else {
                $response = EHealth::deviceRequest()->cancel($this->carePlan->person->uuid, $record->uuid, [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $payload['status_reason'],
                ]);
            }

            if ($response->successful()) {
                $record->update(['status' => 'entered-in-error']);
                $this->showSignatureModal = false;
                $this->refreshCarePlan();
                $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Направлення скасовано в eHealth.']);
            } else {
                throw new \Exception(json_encode($response->getData()));
            }
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to cancel referral validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to cancel referral: ' . $e->getMessage());
            Session::flash('error', 'Не вдалося скасувати направлення: ' . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function loadReferralPrintoutForm(string $requestId): void
    {
        try {
            $this->printableContent = $this->referralLifecycle->buildPrintoutHtml($this->carePlan, $requestId);
        } catch (\RuntimeException $exception) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $exception->getMessage()]);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load referral printout: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося завантажити друковану форму.']);
        }
    }
}
