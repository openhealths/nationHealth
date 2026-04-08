<?php

declare(strict_types=1);

namespace App\Livewire\TreatmentPlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\TreatmentPlan;
use App\Repositories\TreatmentPlanRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use App\Livewire\Person\Records\BasePatientComponent;
use Illuminate\Contracts\View\View;
use Livewire\WithFileUploads;

class TreatmentPlanCreate extends BasePatientComponent
{
    use WithFileUploads;

    public bool $showSignatureModal = false;

    // KEP signature fields
    public string $knedp = '';
    public $keyContainerUpload = null;
    public string $password = '';

    // Care Plan form data
    public array $form = [
        'author'      => '',
        'coAuthors'   => [],
        'category'    => '',
        'title'       => '',
        'intent'      => 'order',
        'terms_of_service' => '',
        'period_start' => '',
        'period_end'   => '',
        'encounter'    => '',
        'description'  => '',
        'note'        => '',
        'inform_with'  => '',
        'supporting_info' => [],
    ];

    public string $patientUuid = '';

    public function mount(LegalEntity $legalEntity, int $id): void
    {
        parent::mount($legalEntity, $id);
    }

    protected function initializeComponent(): void
    {
        $this->patientUuid = $this->uuid;
        $this->form['period_start'] = now()->format('d.m.Y');
        
        // Pre-fill author from current employee
        $employee = Auth::user()?->activeEmployee();
        if ($employee) {
            $party = $employee->party;
            $this->form['author'] = implode(' ', array_filter([
                $party?->last_name, $party?->first_name, $party?->second_name,
            ]));
        }
    }

    /**
     * Validation rules for the main form data.
     */
    protected function rules(): array
    {
        return [
            'form.category'         => 'required|string',
            'form.title'            => 'required|string',
            'form.period_start'     => 'required|string',
            'form.period_end'       => 'nullable|string',
            'form.encounter'        => 'required|string',
            'form.terms_of_service' => 'nullable|string',
            'form.description'      => 'nullable|string',
            'form.note'             => 'nullable|string',
            'form.inform_with'      => 'nullable|string',
        ];
    }

    /**
     * Additional validation rules needed before KEP signing.
     */
    protected function rulesForSigning(): array
    {
        return array_merge($this->rules(), [
            'knedp'              => 'required|string',
            'keyContainerUpload' => 'required|file|max:1024',
            'password'           => 'required|string',
        ]);
    }

    /**
     * Watch period_end and show warning per TZ 3.10.1.2.4.
     */
    public function updatedFormPeriodEnd(): void
    {
        if (!empty($this->form['period_end'])) {
            Session::flash('warning',
                'Увага! Ви зазначаєте кінцевий термін періоду дійсності ' .
                'плану лікування. Зауважте, що отримання пацієнтом медичних послуг, ' .
                'медичних виробів або лікарських засобів за призначенням з цього ' .
                'плану лікування після цієї дати будуть неможливі!'
            );
        }
    }

    /**
     * Save as a local draft (without sending to eHealth).
     */
    public function save(TreatmentPlanRepository $repository): void
    {
        if (Auth::user()?->cannot('create', TreatmentPlan::class)) {
            Session::flash('error', 'У вас немає дозволу на створення плану лікування.');
            return;
        }

        try {
            $this->validate($this->rules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            return;
        }

        $legalEntity = legalEntity();

        $encounterData = $this->resolveEncounterData();

        $repository->create([
            'person_id'       => $this->resolvePersonId(),
            'author_id'       => Auth::user()?->activeEmployee()?->id,
            'legal_entity_id' => $legalEntity?->id,
            'status'          => 'NEW',
            'category'        => $this->form['category'],
            'title'           => $this->form['title'],
            'period_start'    => convertToYmd($this->form['period_start']),
            'period_end'      => !empty($this->form['period_end'])
                ? convertToYmd($this->form['period_end']) : null,
            'terms_of_service' => $this->form['terms_of_service'] ?? null,
            'encounter_id'    => $encounterData['id'],
            'addresses'       => $encounterData['addresses'],
            'description'     => $this->form['description'] ?? null,
            'note'            => $this->form['note'] ?? null,
            'inform_with'     => $this->form['inform_with'] ?? null,
        ]);

        Session::flash('success', 'Чернетку плану лікування успішно збережено.');
        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }

    /**
     * Sign with KEP and send to eHealth.
     */
    public function sign(TreatmentPlanRepository $repository): void
    {
        if (Auth::user()?->cannot('create', TreatmentPlan::class)) {
            Session::flash('error', 'У вас немає дозволу на створення плану лікування.');
            return;
        }

        try {
            $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->showSignatureModal = false;
            return;
        }

        $legalEntity = legalEntity();

        $encounterData = $this->resolveEncounterData();

        // Build eHealth payload
        $carePlanPayload = removeEmptyKeys([
            'intent'          => 'order',
            'status'          => 'new',
            'category'        => $this->form['category'],
            'title'           => $this->form['title'],
            'period'          => array_filter([
                'start' => convertToYmd($this->form['period_start']),
                'end'   => !empty($this->form['period_end'])
                    ? convertToYmd($this->form['period_end']) : null,
            ]),
            'addresses'       => $encounterData['addresses'],
            'encounter'       => ['identifier' => ['value' => $this->form['encounter']]],
            'care_manager'    => ['identifier' => ['value' => Auth::user()?->activeEmployee()?->uuid]],
            'description'     => $this->form['description'] ?: null,
            'note'            => $this->form['note'] ?: null,
            'inform_with'     => $this->form['inform_with'] ?: null,
            'terms_of_service' => $this->form['terms_of_service'] ?: null,
        ]);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($carePlanPayload),
                $this->password,
                $this->knedp,
                $this->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $eHealthResponse = EHealth::carePlan()->create([
                'signed_content'          => $signedContent,
                'signed_content_encoding' => 'base64',
            ]);

            $responseData = $eHealthResponse->getData();

            // Store eHealth response locally
            $repository->create([
                'uuid'            => $responseData['id'] ?? null,
                'person_id'       => $this->resolvePersonId(),
                'author_id'       => Auth::user()?->activeEmployee()?->id,
                'legal_entity_id' => $legalEntity?->id,
                'status'          => $responseData['status'] ?? 'new',
                'category'        => $this->form['category'],
                'title'           => $this->form['title'],
                'period_start'    => convertToYmd($this->form['period_start']),
                'period_end'      => !empty($this->form['period_end'])
                    ? convertToYmd($this->form['period_end']) : null,
                'requisition'     => $responseData['requisition'] ?? null,
            ]);

            Session::flash('success', 'План лікування успішно підписано та відправлено до ЕСОЗ.');
            $this->redirectRoute('persons.index', [legalEntity()], navigate: true);

        } catch (ConnectionException $exception) {
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ.");
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage());
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');
            $this->showSignatureModal = false;
        }
    }

    /**
     * Resolve the person local ID from patientUuid.
     */
    protected function resolvePersonId(): ?int
    {
        if (empty($this->patientUuid)) {
            return null;
        }
        return \App\Models\Person\Person::where('uuid', $this->patientUuid)->value('id');
    }

    /**
     * Resolve the local Encounter ID and extract Conditions (addresses) from it.
     */
    protected function resolveEncounterData(): array
    {
        $data = ['id' => null, 'addresses' => []];
        if (empty($this->form['encounter'])) {
            return $data;
        }

        $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $this->form['encounter'])
            ->with('diagnoses.condition')
            ->first();

        if ($encounter) {
            $data['id'] = $encounter->id;
            
            // Extract the UUID of the conditions (addresses for the care plan)
            $conditionUuids = $encounter->diagnoses
                ->map(fn($d) => $d->condition?->value)
                ->filter()
                ->values()
                ->toArray();
                
            // Use the primary condition (first one per eHealth typical usage for 'addresses' in care plan)
            if (!empty($conditionUuids)) {
                $primaryCondition = $conditionUuids[0];
                $data['addresses'][] = ['identifier' => ['value' => $primaryCondition]];
            }
        }

        return $data;
    }

    public function render(): View
    {
        return view('livewire.treatment-plan.treatment-plan-create');
    }
}
