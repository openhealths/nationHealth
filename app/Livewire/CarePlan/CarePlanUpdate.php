<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Models\LegalEntity;
use Livewire\WithFileUploads;

class CarePlanUpdate extends CarePlanCreate
{
    use WithFileUploads;

    public CarePlan $carePlan;

    public function mount(LegalEntity $legalEntity, $carePlan = null, $encounter = null): void
    {
        $carePlan = $carePlan ?? request()->route('carePlan');
        if (!$carePlan instanceof CarePlan) {
            // Fallback for cases where route binding might not have resolved to model yet
            $carePlan = CarePlan::findOrFail($carePlan);
        }

        $this->carePlan = $carePlan;
        $this->patientUuid = $carePlan->person?->uuid ?? '';

        parent::mount($legalEntity, $carePlan->person_id);

        // Hydrate form from model
        $this->form->patient = $carePlan->person?->full_name ?? '';
        $this->form->medical_number = (string) ($carePlan->encounter_id ?? '');
        $this->form->author = $carePlan->author?->party?->full_name ?? '';
        $this->form->coAuthors = []; // TODO: if co-authors are implemented
        $this->form->category = is_array($carePlan->category) ? ($carePlan->category['coding'][0]['code'] ?? '') : ($carePlan->category ?? '');
        $this->form->clinicalProtocol = $carePlan->clinical_protocol ?? '';
        $this->form->context = $carePlan->context ?? '';
        $this->form->title = $carePlan->title ?? '';
        $this->form->intent = 'order';
        $this->form->periodStart = $carePlan->period_start?->format('d.m.Y') ?? '';
        $this->form->periodEnd = $carePlan->period_end?->format('d.m.Y') ?? '';
        $this->form->encounter = $carePlan->encounter?->uuid ?? '';
        $this->form->description = $carePlan->description ?? '';
        $this->form->note = $carePlan->note ?? '';
        $this->form->informWith = $carePlan->inform_with ?? '';
        $this->form->episodes = $carePlan->supporting_info['episodes'] ?? [];
        $this->form->medicalRecords = $carePlan->supporting_info['medical_records'] ?? [];
        $this->form->knedp = '';
        $this->form->keyContainerUpload = null;
        $this->form->password = '';

        // Load patient auth methods
        $this->authMethods = collect(\App\Enums\Person\AuthenticationMethod::cases())->map(fn ($m) => [
            'value' => $m->value,
            'label' => $m->label(),
        ])->toArray();

        // Load encounter diagnoses for UI
        if ($carePlan->encounter) {
            $this->diagnoses = $carePlan->encounter->diagnoses->map(fn ($d) => [
                'date' => $d->condition?->asserted_date?->format('d.m.Y') ?? '-',
                'name' => $d->condition?->code_display ?? $d->condition?->code ?? '-',
            ])->toArray();
        }

        // Load doctors for co-authors (copied from Create)
        $legalEntity = legalEntity();
        if ($legalEntity) {
            $this->doctors = \App\Models\Employee\Employee::where('legal_entity_id', $legalEntity->id)
                ->whereIn('employee_type', [\App\Enums\User\Role::DOCTOR, \App\Enums\User\Role::SPECIALIST])
                ->where('status', \App\Enums\Status::APPROVED)
                ->where('is_active', true)
                ->with('party')
                ->get()
                ->filter(fn ($e) => $e->party !== null)
                ->map(fn ($e) => [
                    'uuid' => $e->uuid,
                    'name' => ($e->party->full_name ?? 'Unknown') . ' (' . ($e->position ?? '') . ')',
                ])
                ->values()
                ->toArray();
        }

        // Load dictionaries
        try {
            $basics = app(\App\Services\Dictionary\DictionaryManager::class)->basics();
            $this->dictionaries['care_plan_categories'] = $basics->byName('eHealth/care_plan_categories')
                ?->asCodeDescription()
                ?->toArray() ?? [];
            $this->dictionaries['encounter_classes'] = $basics->byName('eHealth/encounter_classes')
                ?->asCodeDescription()
                ?->toArray() ?? [];
            $this->categories = $this->dictionaries['care_plan_categories'];
        } catch (\Exception $exception) {
            Log::warning('CarePlanUpdate: failed to load dictionaries: ' . $exception->getMessage());
        }
    }

    /**
     * Update existing local draft.
     */
    public function save(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('update', $this->carePlan)) {
            session()->flash('error', __('care-plan.no_permission_update'));

            return;
        }

        try {
            $this->form->validate();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $encounterData = $this->resolveEncounterData();

        $repository->updateById($this->carePlan->id, [
            'category' => $this->form->category,
            'clinical_protocol' => $this->form->clinicalProtocol ?: null,
            'context' => $this->form->context ?: null,
            'title' => $this->form->title,
            'period_start' => convertToYmd($this->form->periodStart),
            'period_end' => !empty($this->form->periodEnd)
                ? convertToYmd($this->form->periodEnd) : null,
            'encounter_id' => $encounterData['id'],
            'addresses' => $encounterData['addresses'],
            'supporting_info' => [
                'episodes' => $this->form->episodes,
                'medical_records' => $this->form->medicalRecords,
            ],
            'description' => $this->form->description ?: null,
            'note' => $this->form->note ?: null,
            'inform_with' => $this->form->informWith ?: null,
        ]);

        session()->flash('success', __('care-plan.draft_updated') ?? 'План лікування успішно збережено');

        $this->redirectRoute('care-plans.edit', [legalEntity(), $this->carePlan->id], navigate: true);
    }

    /**
     * Sign with KEP and send to eHealth (Update current plan).
     */
    public function sign(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('update', $this->carePlan)) {
            session()->flash('error', __('care-plan.no_permission_update'));

            return;
        }

        try {
            $this->form->validate($this->form->rulesForSigning());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception, closeModal: true);

            return;
        }

        $encounterData = $this->resolveEncounterData();

        // Build eHealth payload via Repository
        $carePlanPayload = $repository->formatCarePlanRequest(
            $this->form->toArray(),
            $this->form->encounter ?: null,
            $encounterData,
            Auth::user()?->getCarePlanWriterEmployee()?->uuid ?? Auth::user()?->activeEmployee()?->uuid
        );

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($carePlanPayload),
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $eHealthResponse = EHealth::carePlan()->create($this->patientUuid, [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
            ]);

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

            // Extract the actual CarePlan data
            $carePlanUuid = $finalResponse['id'] ?? null;
            $carePlanStatus = $finalResponse['status'] ?? 'new';
            $carePlanRequisition = $finalResponse['requisition'] ?? null;

            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $carePlanUuid = $entity['id'] ?? $carePlanUuid;
                $carePlanStatus = $entity['status'] ?? 'active';
                $carePlanRequisition = $entity['requisition'] ?? $carePlanRequisition;
            }

            // Store to Mongo if configured
            if (config('database.medical_events_db_driver') === 'mongo') {
                try {
                    \App\Models\MedicalEvents\Mongo\CarePlan::create($finalResponse);
                } catch (\Throwable $e) {
                    Log::warning('Failed to save CarePlan to Mongo: ' . $e->getMessage());
                }
            }

            Log::debug('CarePlanUpdate: updating local model with data:', [
                'id' => $this->carePlan->id,
                'uuid' => $carePlanUuid,
                'status' => $carePlanStatus,
                'requisition' => $carePlanRequisition,
                'category' => $this->form->category,
                'encounter_id' => $encounterData['id'],
                'addresses' => $encounterData['addresses'],
            ]);

            // Update local model with eHealth response
            if ($carePlanUuid) {
                try {
                    $detailsResponse = EHealth::carePlan()->getDetails($this->patientUuid ?: $this->uuid, $carePlanUuid);
                    $repository->syncCarePlans($detailsResponse->validate(), $this->carePlan->person_id);
                } catch (\Throwable $e) {
                    Log::warning('CarePlanUpdate: failed to sync care plan after update: ' . $e->getMessage());
                    // Fallback update
                    $repository->updateById($this->carePlan->id, [
                        'uuid' => $carePlanUuid,
                        'status' => $carePlanStatus,
                        'requisition' => $carePlanRequisition,
                        'category' => $this->form->category,
                        'title' => $this->form->title,
                        'period_start' => convertToYmd($this->form->periodStart),
                        'period_end' => !empty($this->form->periodEnd) ? convertToYmd($this->form->periodEnd) : null,
                        'encounter_id' => $encounterData['id'],
                        'addresses' => $encounterData['addresses'],
                        'supporting_info' => [
                            'episodes' => $this->form->episodes,
                            'medical_records' => $this->form->medicalRecords,
                        ],
                    ]);
                }
            } else {
                $repository->updateById($this->carePlan->id, [
                    'status' => $carePlanStatus,
                    'requisition' => $carePlanRequisition,
                ]);
            }

            session()->flash('success', __('care-plan.signed_and_sent'));

            $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlan->id], navigate: true);

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            session()->flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
            session()->flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            session()->flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    public function render()
    {
        // Reuse the same view as Create
        return view('livewire.care-plan.care-plan-create');
    }
}
