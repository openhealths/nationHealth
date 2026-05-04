<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\Exceptions\CipherApiException;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\ConditionMapper;
use App\Services\MedicalEvents\Mappers\DiagnosticReportMapper;
use App\Services\MedicalEvents\Mappers\EncounterMapper;
use App\Services\MedicalEvents\Mappers\ImmunizationMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Attributes\Locked;
use Throwable;

class EncounterEdit extends EncounterComponent
{
    #[Locked]
    public int $encounterId;

    public function mount(LegalEntity $legalEntity, int $personId, int $encounterId): void
    {
        $this->initializeComponent($personId);
        $this->encounterId = $encounterId;

        $encounter = Encounter::withRelationships()->whereId($encounterId)->firstOrFail()->toArray();

        $this->form->encounter = app(EncounterMapper::class)->fromFhir($encounter);

        $episodeUuid = data_get($encounter, 'episode.identifier.value', '');

        $this->episodeType = 'existing';
        $this->form->episode['id'] = $episodeUuid;

        $conditions = Repository::condition()->getByUuids(
            collect(data_get($encounter, 'diagnoses', []))
                ->pluck('condition.identifier.value')
                ->filter()
                ->values()
                ->toArray()
        );

        $detailsMap = Repository::condition()->getDetailsMapForEvidences($conditions);

        if ($conditions) {
            $this->form->conditions = collect($conditions)
                ->map(fn (array $condition) => app(ConditionMapper::class)->fromFhir($condition, $detailsMap))
                ->toArray();
        }

        $immunizations = Repository::immunization()->get($encounter['uuid']);
        if ($immunizations) {
            $this->form->immunizations = collect($immunizations)
                ->map(fn (array $immunization) => app(ImmunizationMapper::class)->fromFhir($immunization))
                ->toArray();
        }

        $diagnosticReports = Repository::diagnosticReport()->get($encounter['uuid']);
        if ($diagnosticReports) {
            $this->form->diagnosticReports = collect($diagnosticReports)
                ->map(fn (array $diagnosticReport) => app(DiagnosticReportMapper::class)->fromFhir($diagnosticReport))
                ->toArray();
        }

        //        $this->form->observations = Repository::observation()->get($this->encounterId);
        //        $this->form->observations = Repository::observation()->formatForView($this->form->observations);
        //
        //        $this->form->procedures = Repository::procedure()->get($this->encounterId);
        //        $this->form->procedures = Repository::procedure()->formatForView($this->form->procedures);
        //
        //        $this->form->clinicalImpressions = Repository::clinicalImpression()->get($this->encounterId);
    }

    /**
     * Validate and update data.
     *
     * @return array|null
     */
    public function save(): ?array
    {
        try {
            $validated = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return null;
        }

        // format to fhir format for local saving(updating)
        $encounter = Encounter::withRelationships()->whereId($this->encounterId)->firstOrFail();
        $uuids = [
            'encounter' => $encounter->uuid,
            'visit' => data_get($encounter->toArray(), 'visit.identifier.value'),
            'employee' => Auth::user()->getEncounterWriterEmployee($validated['encounter']['classCode'])->uuid,
            'episode' => $validated['episode']['id']
        ];
        $fhirConditions = collect($validated['conditions'] ?? [])
            ->map(fn (array $condition) => app(ConditionMapper::class)->toFhir($condition, $uuids))
            ->values()
            ->toArray();
        $fhirImmunizations = collect($validated['immunizations'] ?? [])
            ->map(fn (array $immunization) => app(ImmunizationMapper::class)->toFhir($immunization, $uuids))
            ->values()
            ->toArray();
        $fhirDiagnosticReports = collect($validated['diagnosticReports'] ?? [])
            ->map(fn (array $diagnosticReport) => app(DiagnosticReportMapper::class)->toFhir($diagnosticReport, $uuids))
            ->values()
            ->toArray();
        $fhirEncounter = app(EncounterMapper::class)->toFhir(
            $validated['encounter'],
            $fhirConditions,
            $uuids
        );

        try {
            Repository::encounter()->sync($this->personId, [$this->fhirToSync($fhirEncounter)]);
            Repository::condition()->sync($this->personId, array_map($this->fhirToSync(...), $fhirConditions));
            Repository::immunization()->sync($this->personId, array_map($this->fhirToSync(...), $fhirImmunizations));
            Repository::diagnosticReport()->sync(
                $this->personId,
                array_map($this->fhirToSync(...), $fhirDiagnosticReports)
            );
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Failed to sync encounter package data');
            Session::flash('error', __('messages.database_error'));

            return null;
        }

        Session::flash('success', __('patients.messages.encounter_updated'));

        return [
            'encounter' => $fhirEncounter,
            'conditions' => $fhirConditions,
            'immunizations' => $fhirImmunizations,
            'diagnostic_reports' => $fhirDiagnosticReports,
        ];
    }

    /**
     * Rename 'id' to 'uuid' and convert keys to snake_case for sync methods.
     *
     * @param  array  $fhirItem
     * @return array
     */
    private function fhirToSync(array $fhirItem): array
    {
        return Arr::toSnakeCase(
            collect($fhirItem)->put('uuid', $fhirItem['id'])->forget(['id'])->all()
        );
    }

    /**
     * Submit encrypted data about person encounter.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('create', Encounter::class)) {
            Session::flash('error', __('patients.policy.create_encounter'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->save();
        if (is_null($formattedData)) {
            return;
        }

        $formattedData = Arr::toSnakeCase($formattedData);

        try {
            $signedContent = new CipherRequest()->signData(
                $formattedData,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (ConnectionException|CipherApiException|JsonException $exception) {
            $this->handleCipherExceptions($exception, 'Error when signing data with Cipher');

            return;
        }

        try {
            $resp = EHealth::encounter()->submit($this->patientUuid, [
                'visit' => [
                    'id' => data_get($formattedData, 'encounter.visit.identifier.value'),
                    'period' => data_get($formattedData, 'encounter.period')
                ],
                'signed_data' => $signedContent->getBase64Data()
            ]);

            logger()->debug('Job ID to further debug', $resp->getData());
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while submitting encounter');

            return;
        }

        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }
}
