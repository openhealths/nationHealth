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
use App\Services\MedicalEvents\Fhir;
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

        $this->form->encounter = Fhir::encounter()->fromFhir($encounter);
        $this->episodeType = 'existing';
        $this->form->episode['id'] = data_get($encounter, 'episode.identifier.value', '');

        $this->loadConditions($encounter);
        $this->loadImmunizations($encounter['uuid']);
        $this->loadDiagnosticReports($encounter['uuid']);
        $this->loadObservations($encounter['uuid']);
        $this->loadProcedures($encounter['uuid']);
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

        $encounter = Encounter::withRelationships()->whereId($this->encounterId)->firstOrFail();
        $uuids = [
            'encounter' => $encounter->uuid,
            'visit' => data_get($encounter->toArray(), 'visit.identifier.value'),
            'employee' => Auth::user()->getEncounterWriterEmployee($validated['encounter']['classCode'])->uuid,
            'episode' => $validated['episode']['id']
        ];

        $fhir = Fhir::encounterPackage()->toFhir($validated, $uuids);
        $fhirEncounter = $fhir['encounter'];
        $fhirConditions = $fhir['conditions'];
        $fhirImmunizations = $fhir['immunizations'];
        $fhirDiagnosticReports = $fhir['diagnosticReports'];
        $fhirObservations = $fhir['observations'];
        $fhirProcedures = $fhir['procedures'];

        try {
            Repository::encounter()->sync($this->personId, [$this->fhirToSync($fhirEncounter)]);
            Repository::condition()->sync($this->personId, array_map($this->fhirToSync(...), $fhirConditions));
            Repository::immunization()->sync($this->personId, array_map($this->fhirToSync(...), $fhirImmunizations));
            Repository::diagnosticReport()->sync(
                $this->personId,
                array_map($this->fhirToSync(...), $fhirDiagnosticReports)
            );
            Repository::observation()->sync(
                $this->personId,
                array_map($this->fhirToSync(...), $fhirObservations),
                $uuids['encounter']
            );
            Repository::procedure()->sync(
                $this->personId,
                array_map($this->fhirToSync(...), $fhirProcedures)
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
            'observations' => $fhirObservations,
            'procedures' => $fhirProcedures
        ];
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

    private function loadConditions(array $encounter): void
    {
        $conditions = Repository::condition()->getByUuids(
            collect(data_get($encounter, 'diagnoses', []))
                ->pluck('condition.identifier.value')
                ->filter()
                ->values()
                ->toArray()
        );

        if (!$conditions) {
            return;
        }

        $detailsMap = Repository::condition()->getDetailsMapForEvidences($conditions);

        $this->form->conditions = collect($conditions)
            ->map(fn (array $condition) => Fhir::condition()->fromFhir($condition, $detailsMap))
            ->toArray();
    }

    private function loadImmunizations(string $encounterUuid): void
    {
        $immunizations = Repository::immunization()->get($encounterUuid);

        if (!$immunizations) {
            return;
        }

        $this->form->immunizations = collect($immunizations)
            ->map(fn (array $immunization) => Fhir::immunization()->fromFhir($immunization))
            ->toArray();
    }

    private function loadDiagnosticReports(string $encounterUuid): void
    {
        $diagnosticReports = Repository::diagnosticReport()->get($encounterUuid);

        if (!$diagnosticReports) {
            return;
        }

        $this->form->diagnosticReports = collect($diagnosticReports)
            ->map(fn (array $diagnosticReport) => Fhir::diagnosticReport()->fromFhir($diagnosticReport))
            ->toArray();
    }

    private function loadObservations(string $encounterUuid): void
    {
        $observations = Repository::observation()->get($encounterUuid);

        if (!$observations) {
            return;
        }

        $this->form->observations = collect($observations)
            ->map(fn (array $observation) => Fhir::observation()->fromFhir($observation))
            ->toArray();
    }

    private function loadProcedures(string $encounterUuid): void
    {
        $procedures = Repository::procedure()->get($encounterUuid);

        if (!$procedures) {
            return;
        }

        $conditionUuids = collect($procedures)
            ->flatMap(fn (array $procedure) => array_merge(
                collect(data_get($procedure, 'reasonReferences', []))
                    ->filter(fn ($ref) => data_get($ref, 'identifier.type.coding.0.code') === 'condition')
                    ->pluck('identifier.value')
                    ->toArray(),
                collect(data_get($procedure, 'complicationDetails', []))
                    ->pluck('identifier.value')
                    ->toArray()
            ))
            ->filter()->unique()->values()->toArray();

        $observationUuids = collect($procedures)
            ->flatMap(
                fn (array $procedure) => collect(data_get($procedure, 'reasonReferences', []))
                    ->filter(fn ($ref) => data_get($ref, 'identifier.type.coding.0.code') === 'observation')
                    ->pluck('identifier.value')
                    ->toArray()
            )
            ->filter()->unique()->values()->toArray();

        $detailsMap = array_merge(
            Repository::condition()->getDetailsMapByUuids($conditionUuids),
            Repository::observation()->getDetailsMapByUuids($observationUuids)
        );

        $this->form->procedures = collect($procedures)
            ->map(fn (array $procedure) => Fhir::procedure()->fromFhir($procedure, $detailsMap))
            ->toArray();
    }
}
