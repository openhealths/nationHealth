<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanRepository;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class CarePlanShow extends Component
{
    use WithFileUploads;

    public CarePlan $carePlan;

    public bool $showSignatureModal = false;
    public string $actionType = ''; // 'cancel', 'complete', 'sign_activity', 'complete_activity', 'cancel_activity'
    public string $statusReason = ''; // Used when cancelling or completing
    public ?int $activityToSign = null;
    public array $dictionaries = [];

    // Activity Form state
    public array $activityForm = [
        'kind' => 'service_request',
        'program' => '',
        'quantity' => '',
        'quantity_system' => '',
        'quantity_code' => '',
        'daily_amount' => '',
        'reason_code' => '',
        'reason_reference' => '',
        'goal' => '',
        'description' => '',
        'scheduled_period_start' => '',
        'scheduled_period_end' => '',
        'product_reference' => '',
        'product_codeable_concept' => '',
    ];

    public array $form = [
        'knedp' => '',
        'keyContainerUpload' => null,
        'password' => '',
    ];

    public function mount(CarePlan $carePlan): void
    {
        $this->carePlan = $carePlan;

        try {
            $basics = app(\App\Services\Dictionary\DictionaryManager::class)->basics();
            $this->dictionaries['care_plan_categories'] = $basics->byName('eHealth/care_plan_categories')
                ?->asCodeDescription()
                ?->toArray() ?? [];
        } catch (\Exception $exception) {
            Log::warning('CarePlanShow: failed to load dictionaries: ' . $exception->getMessage());
        }
    }

    protected function rulesForSigning(): array
    {
        return [
            'statusReason' => 'required|string',
            'form.knedp' => 'required|string',
            'form.keyContainerUpload' => 'required|file|max:1024',
            'form.password' => 'required|string',
        ];
    }

    public function openSignatureModal(string $actionType, ?int $activityId = null): void
    {
        $this->actionType = $actionType;
        $this->activityToSign = $activityId;
        $this->statusReason = ''; // Reset reason
        $this->showSignatureModal = true;
    }

    public function initActivityForm(string $kind): void
    {
        $this->activityForm['kind'] = $kind;
        $this->activityForm['scheduled_period_start'] = now()->format('d.m.Y');
    }

    public function saveActivity(CarePlanActivityRepository $repository): void
    {
        try {
            $validated = $this->validate([
                'activityForm.kind' => 'required|string',
                'activityForm.scheduled_period_start' => 'required|string',
                'activityForm.quantity' => 'nullable|numeric',
                'activityForm.description' => 'nullable|string',
                'activityForm.product_reference' => 'nullable|string',
            ]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            return;
        }

        $repository->create([
            'care_plan_id' => $this->carePlan->id,
            'author_id' => Auth::user()?->activeEmployee()?->id,
            'status' => 'NEW',
            'kind' => $validated['activityForm']['kind'],
            'quantity' => $validated['activityForm']['quantity'] ?? null,
            'description' => $validated['activityForm']['description'] ?? null,
            'product_reference' => $validated['activityForm']['product_reference'] ?? null,
            'scheduled_period_start' => convertToYmd($validated['activityForm']['scheduled_period_start']),
            'scheduled_period_end' => !empty($this->activityForm['scheduled_period_end'])
                ? convertToYmd($this->activityForm['scheduled_period_end']) : null,
        ]);

        $this->carePlan->refresh();
        Session::flash('success', __('care-plan.activity_draft_saved'));

        // Close drawers
        $this->dispatch('close-drawers');
    }

    public function sign(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        try {
            $validated = $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->showSignatureModal = false;
            return;
        }

        if ($this->actionType === 'sign_activity') {
            $this->signActivity($activityRepository);
            return;
        }

        if (in_array($this->actionType, ['complete_activity', 'cancel_activity'])) {
            $this->signStatusActivity($activityRepository);
            return;
        }

        if (empty($this->carePlan->uuid)) {
            if ($this->actionType === 'sign_plan') {
                $this->signPlan($repository);
                return;
            }
            Session::flash('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;
            return;
        }

        // Action-specific payload
        $statusMap = [
            'cancel' => 'entered_in_error', // or cancelled, depends on exact spec constraints
            'complete' => 'completed',
        ];

        $payload = [
            'status' => $statusMap[$this->actionType] ?? 'cancelled',
            'status_reason' => $this->statusReason,
        ];

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            // Send to eHealth based on action type
            $apiMethod = $this->actionType === 'complete' ? 'complete' : 'cancel';
            
            $eHealthResponse = EHealth::carePlan()->{$apiMethod}(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data'          => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            // Job Polling
            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = new \App\Classes\eHealth\Api\Job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            // Extract status
            $carePlanStatus = $finalResponse['status'] ?? $payload['status'];
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $carePlanStatus = $entity['status'] ?? $carePlanStatus;
            }

            // Update local state
            $repository->updateById($this->carePlan->id, [
                'status' => $carePlanStatus,
            ]);

            $this->carePlan->refresh();

            Session::flash('success', __('care-plan.care_plan_updated'));
            $this->showSignatureModal = false;

        } catch (ConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signPlan(CarePlanRepository $repository): void
    {
        $legalEntity = legalEntity();

        // Build eHealth payload from model
        $carePlanPayload = removeEmptyKeys([
            'intent' => 'order',
            'status' => 'new',
            'category' => is_array($this->carePlan->category) ? ($this->carePlan->category['coding'][0]['code'] ?? null) : $this->carePlan->category,
            'instantiates_protocol' => $this->carePlan->clinical_protocol ? [['display' => $this->carePlan->clinical_protocol]] : null,
            'context' => $this->carePlan->context ? ['identifier' => ['type_code' => $this->carePlan->context]] : null,
            'title' => $this->carePlan->title,
            'period' => array_filter([
                'start' => $this->carePlan->period_start ? $this->carePlan->period_start->format('Y-m-d') : null,
                'end' => $this->carePlan->period_end ? $this->carePlan->period_end->format('Y-m-d') : null,
            ]),
            'addresses' => $this->carePlan->addresses, // Already stored as array of diagnoses
            'supporting_info' => array_merge(
                array_map(fn($e) => ['display' => $e['name']], $this->carePlan->supporting_info['episodes'] ?? []),
                array_map(fn($m) => ['display' => $m['name']], $this->carePlan->supporting_info['medical_records'] ?? [])
            ),
            'encounter' => $this->carePlan->encounter?->uuid ? ['identifier' => ['value' => $this->carePlan->encounter->uuid]] : null,
            'care_manager' => ['identifier' => ['value' => Auth::user()?->activeEmployee()?->uuid]],
            'description' => $this->carePlan->description ?: null,
            'note' => $this->carePlan->note ?: null,
            'inform_with' => $this->carePlan->inform_with ?: null,
        ]);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($carePlanPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $eHealthResponse = EHealth::carePlan()->create($this->carePlan->person->uuid, [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
            ]);

            $responseData = $eHealthResponse->getData();

            // Update local model
            $repository->updateById($this->carePlan->id, [
                'uuid' => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? 'new',
                'requisition' => $responseData['requisition'] ?? null,
            ]);

            $this->carePlan->refresh();

            Session::flash('success', __('care-plan.signed_and_sent'));
            $this->showSignatureModal = false;

        } catch (ConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            Session::flash('error', __('care-plan.no_activity_selected'));
            $this->showSignatureModal = false;
            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) {
            Session::flash('error', __('care-plan.activity_not_found'));
            $this->showSignatureModal = false;
            return;
        }

        // Build Payload
        $activityPayload = $activityRepository->formatCarePlanActivityRequest($activity);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($activityPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $eHealthResponse = EHealth::carePlanActivity()->create(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            // If it is an async job, poll it
            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = new \App\Classes\eHealth\Api\Job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            // Extract the actual CarePlanActivity data
            $activityUuid = $finalResponse['id'] ?? null;
            $activityStatus = $finalResponse['status'] ?? 'new';
            
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $activityUuid = $entity['id'] ?? $activityUuid;
                $activityStatus = $entity['status'] ?? 'active';
            }

            // Store to Mongo
            try {
                \App\Models\MedicalEvents\Mongo\CarePlanActivity::create($finalResponse);
            } catch (\Exception $e) {
                Log::warning('Failed to save CarePlanActivity to Mongo: ' . $e->getMessage());
            }

            $activityRepository->updateById($activity->id, [
                'status' => $activityStatus,
                'uuid' => $activityUuid,
            ]);

            $this->carePlan->refresh();
            Session::flash('success', __('care-plan.activity_signed'));
            $this->showSignatureModal = false;

        } catch (ConnectionException $exception) {
            Log::error('CarePlanActivity: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            Log::error('CarePlanActivity: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivity: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signStatusActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            Session::flash('error', __('care-plan.no_activity_selected'));
            $this->showSignatureModal = false;
            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) return;

        $statusMap = [
            'cancel_activity' => 'entered_in_error', // or cancelled
            'complete_activity' => 'completed',
        ];

        $payload = [
            'status' => $statusMap[$this->actionType] ?? 'cancelled',
            'status_reason' => $this->statusReason,
        ];

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $apiMethod = $this->actionType === 'complete_activity' ? 'complete' : 'cancel';
            
            $eHealthResponse = EHealth::carePlanActivity()->{$apiMethod}(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                $activity->uuid,
                [
                    'signed_data'          => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = new \App\Classes\eHealth\Api\Job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            $activityStatus = $finalResponse['status'] ?? $payload['status'];
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $activityStatus = $entity['status'] ?? $activityStatus;
            }

            $activityRepository->updateById($activity->id, [
                'status' => $activityStatus,
            ]);

            $this->carePlan->refresh();
            Session::flash('success', __('care-plan.activity_updated'));
            $this->showSignatureModal = false;

        } catch (\Throwable $exception) {
            Log::error('CarePlanActivityStatus: error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-show');
    }
}
