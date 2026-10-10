<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Dto\CarePlan\Ehealth as CarePlanEhealthData;
use App\Dto\CarePlan\Model as CarePlanModelData;
use App\Dto\CarePlan\Form as CarePlanFormData;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

class CarePlanUpdate extends CarePlanCreate
{
    use WithFileUploads;

    public CarePlan $carePlan;

    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null, $carePlan = null): void
    {
        $carePlan = $carePlan ?? request()->route('carePlan');
        if (!$carePlan instanceof CarePlan) {
            // Fallback for cases where route binding might not have resolved to model yet
            $carePlan = CarePlan::findOrFail($carePlan);
        }

        $this->carePlan = $carePlan;
        $this->id = $carePlan->personId;
        $this->patientUuid = $carePlan->person?->uuid ?? '';

        parent::mount($legalEntity, $this->id);

        $carePlan->loadMissing(['person', 'author.party', 'encounter']);
        $this->form->fill(app(ObjectMapperInterface::class)->map($carePlan, CarePlanFormData::class)->toArray());

        // Load patient auth methods is handled by parent::mount

        // Load encounter diagnoses for UI
        if ($carePlan->encounter) {
            $this->diagnoses = $this->buildDiagnosesForUi($carePlan->encounter);
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
            Session::flash('error', __('care-plan.no_permission_update'));

            return;
        }

        try {
            $this->form->validate();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $encounterData = $this->resolveEncounterData();

        // Re-resolve the author for the (possibly changed) terms_of_service, same as on create,
        // so a draft edited to a different "умови надання послуг" keeps a matching author.
        $author = Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null);

        $repository->updateById($this->carePlan->id, array_replace(
            app(ObjectMapperInterface::class)->map($this->form, CarePlanModelData::class)->toArray(),
            [
                'author_id' => $author?->id ?? $this->carePlan->author_id,
                'encounter_id' => $encounterData['id'],
                'addresses' => $encounterData['addresses'],
            ]
        ));

        session()->flash('success', __('care-plan.draft_updated') ?? 'План лікування успішно збережено');

        $this->redirectRoute('care-plans.edit', [legalEntity(), $this->carePlan->id], navigate: true);
    }

    public function delete(CarePlanRepository $repository): void
    {
        if (isset($this->carePlan) && $this->carePlan->exists) {
            if ($this->carePlan->status === 'draft' || $this->carePlan->status === 'new') {
                $this->carePlan->delete();
                session()->flash('success', __('Чернетку плану лікування успішно видалено.'));
            } else {
                session()->flash('error', __('Можна видаляти лише чернетки планів лікування.'));
            }
        }

        $encounter = $this->carePlan->encounter;
        if ($encounter) {
            $this->redirectRoute('encounter.edit', [legalEntity(), $this->personId, $encounter->id], navigate: true);

            return;
        }

        $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);
    }

    /**
     * Sign with KEP and send to eHealth (Update current plan).
     */
    public function sign(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('update', $this->carePlan)) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return;
        }

        try {
            $this->form->validate($this->form->rulesForSigning());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception, closeModal: true);

            return;
        }

        $encounterData = $this->resolveEncounterData();

        $termsOfService = $this->form->termsOfService;
        $author = Auth::user()?->getCarePlanWriterEmployee($termsOfService);
        $this->logCarePlanAuthorRoleDebug($author, $termsOfService);

        $carePlanPayload = app(ObjectMapperInterface::class)->map(
            $this->form,
            new CarePlanEhealthData(
                (string) Str::uuid(),
                $author?->uuid,
                $encounterData,
                config('app.timezone', 'Europe/Kyiv'),
            )
        )->toArray();

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($carePlanPayload),
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $finalResponse = EHealth::carePlan()->createSignedAndResolve($this->patientUuid, $signedContent);

            if (($finalResponse['status'] ?? null) === 'failed') {
                throw new \App\Exceptions\EHealth\EHealthValidationException($finalResponse);
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

            // Update local model with eHealth response
            $repository->updateById($this->carePlan->id, array_filter([
                'uuid' => $carePlanUuid,
                'status' => $carePlanStatus,
                'requisition' => $carePlanRequisition,
                // Update other fields too just in case they were changed before signing
                'author_id' => $author?->id ?? $this->carePlan->author_id,
                'terms_of_service' => $termsOfService ?: null,
                'category' => $this->form->category,
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
                'context' => $this->form->context ?: null,
                'description' => $this->form->description ?: null,
                'note' => $this->form->note ?: null,
                'inform_with' => $this->form->informWith ?: null,
            ], static fn (mixed $value): bool => $value !== null));

            session()->flash('success', __('care-plan.signed_and_sent'));

            $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlan->id], navigate: true);

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    public function render()
    {
        // Reuse the same view as Create
        return view('livewire.care-plan.care-plan-create');
    }
}
