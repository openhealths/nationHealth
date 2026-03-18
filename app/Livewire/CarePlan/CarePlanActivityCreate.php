<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class CarePlanActivityCreate extends Component
{
    use WithFileUploads;

    public string $carePlanId;
    public string $carePlanUuid; // Needed for eHealth URL

    public bool $showSignatureModal = false;

    // KEP signature fields
    public string $knedp = '';
    public $keyContainerUpload = null;
    public string $password = '';

    // Activity form data
    public array $form = [
        'kind'                     => 'medication_request', // medication_request, device_request, service_request
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

    public function mount(string $carePlanId, string $carePlanUuid): void
    {
        $this->carePlanId = $carePlanId;
        $this->carePlanUuid = $carePlanUuid;
        $this->form['scheduled_period_start'] = now()->format('d.m.Y');
    }

    protected function rules(): array
    {
        return [
            'form.kind'                   => 'required|in:medication_request,device_request,service_request',
            'form.scheduled_period_start' => 'required|string',
            'form.scheduled_period_end'   => 'nullable|string',
            'form.quantity'               => 'nullable|numeric|min:1',
            'form.program'                => 'nullable|string',
            'form.description'            => 'nullable|string',
            // ... more extensive rules depending on kind
        ];
    }

    protected function rulesForSigning(): array
    {
        return array_merge($this->rules(), [
            'knedp'              => 'required|string',
            'keyContainerUpload' => 'required|file|max:1024',
            'password'           => 'required|string',
        ]);
    }

    public function save(CarePlanActivityRepository $repository): void
    {
        try {
            $validated = $this->validate($this->rules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            return;
        }

        $repository->create([
            'care_plan_id'             => $this->carePlanId,
            'author_id'                => Auth::user()?->activeEmployee()?->id,
            'status'                   => 'NEW',
            'kind'                     => $validated['form']['kind'],
            'program'                  => $validated['form']['program'] ?? null,
            'quantity'                 => $validated['form']['quantity'] ?? null,
            'description'              => $validated['form']['description'] ?? null,
            'scheduled_period_start'   => convertToYmd($validated['form']['scheduled_period_start']),
            'scheduled_period_end'     => !empty($validated['form']['scheduled_period_end'])
                                            ? convertToYmd($validated['form']['scheduled_period_end']) : null,
            // the rest per kind requirements
        ]);

        Session::flash('success', 'Чернетку призначення успішно збережено.');
        $this->redirectRoute('care-plans.index', [legalEntity()], navigate: true);
    }

    public function sign(CarePlanActivityRepository $repository): void
    {
        try {
            $validated = $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->showSignatureModal = false;
            return;
        }

        // Build Payload
        $activityPayload = removeEmptyKeys([
            'status' => 'scheduled',
            'do_not_perform' => false,
            'detail' => removeEmptyKeys([
                'kind' => $this->form['kind'],
                // specific payloads depend heavily on 'kind' (medication, device, service)
                // for this skeleton, using generic layout
                'description' => $this->form['description'] ?: null,
                'scheduled_period' => array_filter([
                    'start' => convertToYmd($this->form['scheduled_period_start']),
                    'end'   => !empty($this->form['scheduled_period_end'])
                                ? convertToYmd($this->form['scheduled_period_end']) : null,
                ]),
            ]),
            'program' => $this->form['program'] ? ['identifier' => ['value' => $this->form['program']]] : null,
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
                $this->carePlanUuid,
                [
                    'signed_content'          => $signedContent,
                    'signed_content_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();

            $repository->create([
                'uuid'                   => $responseData['id'] ?? null,
                'care_plan_id'           => $this->carePlanId,
                'author_id'              => Auth::user()?->activeEmployee()?->id,
                'status'                 => $responseData['status'] ?? 'scheduled',
                'kind'                   => $this->form['kind'],
                'program'                => $this->form['program'] ?? null,
                'quantity'               => $this->form['quantity'] ?? null,
                'description'            => $this->form['description'] ?? null,
                'scheduled_period_start' => convertToYmd($this->form['scheduled_period_start']),
                'scheduled_period_end'   => !empty($this->form['scheduled_period_end'])
                                              ? convertToYmd($this->form['scheduled_period_end']) : null,
            ]);

            Session::flash('success', 'Призначення успішно підписано та створено.');
            $this->redirectRoute('care-plans.index', [legalEntity()], navigate: true);

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
        return view('livewire.care-plan.care-plan-activity-create');
    }
}
