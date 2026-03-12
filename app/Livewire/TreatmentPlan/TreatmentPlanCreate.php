<?php

namespace App\Livewire\TreatmentPlan;

use App\Classes\eHealth\Api\CarePlan;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
// no request api included
use App\Models\TreatmentPlan;
use App\Repositories\TreatmentPlanRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class TreatmentPlanCreate extends Component
{
    use WithFileUploads;

    public $treatmentPlan = [];

    // KEP signature fields
    public bool $showSignatureModal = false;
    public array $form = [
        'knedp' => '',
        'keyContainerUpload' => null,
        'password' => ''
    ];
    public ?object $file = null;

    /**
     * @var string
     */
    public string $patientUuid;

    /**
     * Rules for validating KEP signing inputs
     */
    protected function rulesForSigning(): array
    {
        return [
            'form.knedp' => 'required|string',
            'form.keyContainerUpload' => 'required|file|max:1024',
            'form.password' => 'required|string',
            'treatmentPlan.title' => 'required|string',
            'treatmentPlan.description' => 'nullable|string',
            'treatmentPlan.status' => 'required|string',
            'treatmentPlan.intent' => 'required|string',
            'treatmentPlan.period_start' => 'required|date',
            'treatmentPlan.period_end' => 'nullable|date|after_or_equal:treatmentPlan.period_start',
        ];
    }

    public function mount(string $patientUuid = 'test-patient-uuid'): void
    {
        $this->patientUuid = $patientUuid;
        $this->treatmentPlan = [
            'title' => '',
            'description' => '',
            'status' => 'draft',
            'intent' => 'plan',
            'period_start' => date('Y-m-d'),
            'period_end' => '',
        ];
    }

    public function updatedFormKeyContainerUpload($val): void
    {
        // Livewire automatically handles updating array values, no manual mapping needed if bound correctly
    }

    public function sign(): void
    {
        if (Auth::user()?->cannot('create', TreatmentPlan::class)) {
            Session::flash('error', 'У вас немає дозволу на створення плану лікування.');
            return;
        }

        try {
            $validated = $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->showSignatureModal = false;
            return;
        }

        // Generate eHealth array representation natively
        $formattedData = [
            'care_plan' => [
                'title' => $this->treatmentPlan['title'],
                'description' => $this->treatmentPlan['description'],
                'status' => 'new',
                'intent' => $this->treatmentPlan['intent'],
                'period' => [
                    'start' => $this->treatmentPlan['period_start'],
                ]
            ]
        ];
        
        if (!empty($this->treatmentPlan['period_end'])) {
            $formattedData['care_plan']['period']['end'] = $this->treatmentPlan['period_end'];
        }

        // Format and sign data
        try {
            // Depending on architecture, we pass the wrapped payload to sign
            $signedContent = signatureService()->signData(
                $formattedData,
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->tax_id
            );

            // Directly build signed payload wrapper to submit
            $submitPackage = [
                'signed_content' => $signedContent, 
                'signed_content_encoding' => 'base64'
            ];

            // Send to eHealth API 
            // eHealth -> care_plans -> CarePlan::create(...) will call the eHealth endpoint via Post
            $eHealthResponse = (new CarePlan())->create($this->patientUuid, $submitPackage);

            // Store local active record
            $createdPlan = TreatmentPlanRepository::create(array_merge($this->treatmentPlan, [
                'status' => 'active',
                'patient_id' => 1, // Mock patient ID hook or $this->patientId if it was resolved
                'uuid' => $eHealthResponse->getData()['id'] ?? null // store eHealth UUID
            ]));

            Session::flash('success', 'План лікування успішно підписано та відправлено.');
            $this->redirectRoute('persons.index', [legalEntity()], navigate: true);

        } catch (ConnectionException $exception) {
            Log::error('Error connecting when creating a care plan: ' . $exception->getMessage());
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ.");
            $this->showSignatureModal = false;
            return;
        } catch (EHealthValidationException | EHealthResponseException $exception) {
            Log::error('Error when creating a care plan: ' . $exception->getMessage());
            $errorMessage = $exception instanceof EHealthValidationException 
                ? $exception->getFormattedMessage() 
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
                
            Session::flash('error', $errorMessage);
            $this->showSignatureModal = false;
            return;
        } catch (\Throwable $exception) {
            Log::error('Error saving care plan: ' . $exception->getMessage());
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');
            $this->showSignatureModal = false;
            return;
        }
    }

    public function render()
    {
        return view('livewire.treatment-plan.treatment-plan-create');
    }
}
