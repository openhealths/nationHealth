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
    public string $actionType = ''; // 'cancel', 'complete', 'sign_activity'
    public string $statusReason = ''; // Used when cancelling or completing
    public ?int $activityToSign = null;

    // Activity Form state
    public array $activityForm = [
        'kind'                     => 'service_request',
        'program'                  => '',
        'quantity'                 => '',
        'quantity_system'          => '',
        'quantity_code'            => '',
        'daily_amount'             => '',
        'reason_code'              => '',
        'reason_reference'         => '',
        'goal'                     => '',
        'description'              => '',
        'scheduled_period_start'   => '',
        'scheduled_period_end'     => '',
        'product_reference'        => '',
        'product_codeable_concept' => '',
    ];

    // KEP signature fields
    public string $knedp = '';
    public $keyContainerUpload = null;
    public string $password = '';

    public function mount(CarePlanRepository $repository, int $carePlan): void
    {
        $plan = $repository->findById($carePlan);
        if (!$plan) {
            abort(404, 'Care Plan not found');
        }
        $this->carePlan = $plan;
    }

    protected function rulesForSigning(): array
    {
        return [
            'statusReason'       => 'required|string',
            'knedp'              => 'required|string',
            'keyContainerUpload' => 'required|file|max:1024',
            'password'           => 'required|string',
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
            'care_plan_id'             => $this->carePlan->id,
            'author_id'                => Auth::user()?->activeEmployee()?->id,
            'status'                   => 'NEW',
            'kind'                     => $validated['activityForm']['kind'],
            'quantity'                 => $validated['activityForm']['quantity'] ?? null,
            'description'              => $validated['activityForm']['description'] ?? null,
            'product_reference'        => $validated['activityForm']['product_reference'] ?? null,
            'scheduled_period_start'   => convertToYmd($validated['activityForm']['scheduled_period_start']),
            'scheduled_period_end'     => !empty($this->activityForm['scheduled_period_end'])
                                            ? convertToYmd($this->activityForm['scheduled_period_end']) : null,
        ]);

        $this->carePlan->refresh();
        Session::flash('success', 'Чернетку призначення успішно збережено.');

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

        if (empty($this->carePlan->uuid)) {
            Session::flash('error', 'Цей план лікування ще не синхронізовано з ЕСОЗ.');
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
                $this->password,
                $this->knedp,
                $this->keyContainerUpload,
                Auth::user()->party->taxId
            );

            // Send to eHealth based on action type
            $apiMethod = $this->actionType === 'complete' ? 'complete' : 'cancel';
            
            $eHealthResponse = EHealth::carePlan()->{$apiMethod}(
                $this->carePlan->uuid,
                [
                    'signed_content'          => $signedContent,
                    'signed_content_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();

            // Update local state
            $repository->updateById($this->carePlan->id, [
                'status' => $responseData['status'] ?? $payload['status'],
            ]);

            $this->carePlan->refresh();

            Session::flash('success', 'План лікування успішно оновлено в ЕСОЗ.');
            $this->showSignatureModal = false;

        } catch (ConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ.");
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');
            $this->showSignatureModal = false;
        }
    }

    private function signActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            Session::flash('error', 'Не вказано призначення для підпису.');
            $this->showSignatureModal = false;
            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) {
            Session::flash('error', 'Призначення не знайдено.');
            $this->showSignatureModal = false;
            return;
        }

        // Build Payload
        $activityPayload = removeEmptyKeys([
            'status' => 'scheduled',
            'do_not_perform' => false,
            'detail' => removeEmptyKeys([
                'kind' => $activity->kind,
                'description' => $activity->description ?: null,
                'scheduled_period' => array_filter([
                    'start' => $activity->scheduled_period_start ? convertToYmd($activity->scheduled_period_start->format('d.m.Y')) : null,
                    'end'   => $activity->scheduled_period_end ? convertToYmd($activity->scheduled_period_end->format('d.m.Y')) : null,
                ]),
            ]),
            'program' => $activity->program ? ['identifier' => ['value' => $activity->program]] : null,
        ]);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($activityPayload),
                $this->password,
                $this->knedp,
                $this->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $eHealthResponse = EHealth::carePlanActivity()->create(
                $this->carePlan->uuid,
                [
                    'signed_content'          => $signedContent,
                    'signed_content_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();

            $activityRepository->updateById($activity->id, [
                'uuid'   => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? 'scheduled',
            ]);

            $this->carePlan->refresh();
            Session::flash('success', 'Призначення успішно підписано та створено в ЕСОЗ.');
            $this->showSignatureModal = false;

        } catch (ConnectionException $exception) {
            Log::error('CarePlanActivity: connection error: ' . $exception->getMessage());
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ.");
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            Log::error('CarePlanActivity: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivity: unexpected error: ' . $exception->getMessage());
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');
            $this->showSignatureModal = false;
        }
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-show');
    }
}
