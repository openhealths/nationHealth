<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class EncounterEdit extends EncounterComponent
{
    #[Locked]
    public int $encounterId;

    public function mount(LegalEntity $legalEntity, int $encounterId, ?Person $person = null, ?Preperson $preperson = null): void
    {
        if ($preperson !== null) {
            $this->prepersonId = $preperson->id;
        } else {
            $this->personId = $person->id;
        }

        $this->initializeComponent();
        $this->encounterId = $encounterId;

        $encounter = Encounter::withRelationships()->whereId($encounterId)->firstOrFail()->toArray();
        $supportingInfoDetails = $this->getEncounterSupportingInfoDetailsMap($encounter);

        $this->form->encounter = Fhir::encounter()->fromFhir($encounter, $supportingInfoDetails);
        $this->episodeType = 'existing';
        $this->form->episode = array_merge($this->form->episode, Fhir::episode()->fromFhir($encounter));

        $this->loadConditions($encounter);
        $this->loadImmunizations($encounter['uuid']);
        $this->loadDiagnosticReports($encounter['uuid']);
        $this->loadObservations($encounter['uuid']);
        $this->loadProcedures($encounter['uuid']);
        $this->loadClinicalImpressions($encounter['uuid']);
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
        $fhirClinicalImpressions = $fhir['clinicalImpressions'];

        try {
            Repository::encounter()->sync($this->patient(), [$this->fhirToSync($fhirEncounter)]);
            Repository::condition()->sync($this->patient(), array_map($this->fhirToSync(...), $fhirConditions));
            Repository::immunization()->sync($this->patient(), array_map($this->fhirToSync(...), $fhirImmunizations));
            Repository::diagnosticReport()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirDiagnosticReports)
            );
            Repository::observation()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirObservations),
                $uuids['encounter']
            );
            Repository::procedure()->sync($this->patient(), array_map($this->fhirToSync(...), $fhirProcedures));
            Repository::clinicalImpression()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirClinicalImpressions)
            );
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to sync encounter package data');

            return null;
        }

        Session::flash('success', __('patients.messages.encounter_updated'));

        return [
            'encounter' => $fhirEncounter,
            'conditions' => $fhirConditions,
            'immunizations' => $fhirImmunizations,
            'diagnosticReports' => $fhirDiagnosticReports,
            'observations' => $fhirObservations,
            'procedures' => $fhirProcedures,
            'clinicalImpressions' => $fhirClinicalImpressions
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
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing data with Cipher');

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

            $jobId = $resp->getData()['job_id'] ?? null;
            if (!$jobId && isset($resp->getData()['links'][0]['href'])) {
                $jobId = basename($resp->getData()['links'][0]['href']);
            }

            if (!$jobId) {
                throw new \RuntimeException('Не вдалося отримати Job ID від ЕСОЗ.');
            }

            $jobApi = EHealth::job();
            $attempts = 0;
            do {
                sleep(2);
                $finalResponse = $jobApi->getDetails($jobId)->getData();
                $attempts++;
                $status = strtolower((string) ($finalResponse['status'] ?? ''));
            } while (in_array($status, ['pending', 'accepted', 'processing'], true) && $attempts < 15);

            if ($status !== 'processed' && $status !== 'active') {
                $errorHandler = new \App\Classes\eHealth\Errors\ErrorHandler();
                $errorResult = $errorHandler->handleError($finalResponse);
                $errorMessages = $errorResult['errors'] ?? [];

                if (empty($errorMessages) || $errorMessages[0] === 'No valid error information provided.') {
                    $fallbackMsg = data_get($finalResponse, 'error.message')
                        ?? data_get($finalResponse, 'message')
                        ?? 'Unknown eHealth Error';
                    $errorMessages = [$fallbackMsg];
                }

                $formattedError = implode("\n", $errorMessages);
                throw new \RuntimeException($formattedError);
            }

            $encounterUuid = $formattedData['encounter']['id'];
            $syncData = EHealth::encounter()->getById($this->patientUuid, $encounterUuid)->validate();
            Repository::encounter()->sync($this->patient(), [$syncData]);

            Session::flash('success', 'Взаємодію успішно підписано та надіслано до ЕСОЗ.');
            $this->showSignatureModal = false;

            if ($this->prepersonId !== null) {
                $this->redirectRoute(
                    'prepersons.encounter.edit',
                    [legalEntity(), 'preperson' => $this->prepersonId, 'encounterId' => $this->encounterId],
                    navigate: true
                );
            } else {
                $this->redirectRoute(
                    'encounter.edit',
                    [legalEntity(), 'person' => $this->personId, 'encounterId' => $this->encounterId],
                    navigate: true
                );
            }

        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while submitting encounter');
            $this->showSignatureModal = false;
        } catch (\RuntimeException $exception) {
            logger()->error('Encounter submission runtime error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            logger()->error('Encounter submission unexpected error: ' . $exception->getMessage(), [
                'trace' => $exception->getTraceAsString(),
            ]);
            Session::flash('error', __('patients.messages.unexpected_error') ?? 'Виникла непередбачувана помилка.');
            $this->showSignatureModal = false;
        }
    }

    protected function loadConditions(array $encounter): void
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

    protected function loadImmunizations(string $encounterUuid): void
    {
        $immunizations = Repository::immunization()->get($encounterUuid);

        if (!$immunizations) {
            return;
        }

        $this->form->immunizations = collect($immunizations)
            ->map(fn (array $immunization) => Fhir::immunization()->fromFhir($immunization))
            ->toArray();
    }

    protected function loadDiagnosticReports(string $encounterUuid): void
    {
        $diagnosticReports = Repository::diagnosticReport()->get($encounterUuid);

        if (!$diagnosticReports) {
            return;
        }

        $this->form->diagnosticReports = collect($diagnosticReports)
            ->map(fn (array $diagnosticReport) => Fhir::diagnosticReport()->fromFhir($diagnosticReport))
            ->toArray();
    }

    protected function loadObservations(string $encounterUuid): void
    {
        $observations = Repository::observation()->get($encounterUuid);

        if (!$observations) {
            return;
        }

        $this->form->observations = collect($observations)
            ->map(fn (array $observation) => Fhir::observation()->fromFhir($observation))
            ->toArray();
    }

    protected function loadProcedures(string $encounterUuid): void
    {
        $procedures = Repository::procedure()->get($encounterUuid);

        if (!$procedures) {
            return;
        }

        $conditionUuids = collect($procedures)
            ->flatMap(fn (array $procedure) => array_merge(
                collect(data_get($procedure, 'reasonReferences', []))
                    ->filter(fn (array $reference) => data_get($reference, 'identifier.type.coding.0.code') === 'condition')
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
                    ->filter(fn (array $reference) => data_get($reference, 'identifier.type.coding.0.code') === 'observation')
                    ->pluck('identifier.value')
                    ->toArray()
            )
            ->filter()->unique()->values()->toArray();

        $detailsMap = array_merge(
            Repository::condition()->getProcedureReferenceDetailsMapByUuids($conditionUuids),
            Repository::observation()->getDetailsMapByUuids($observationUuids)
        );

        $this->form->procedures = collect($procedures)
            ->map(fn (array $procedure) => Fhir::procedure()->fromFhir($procedure, $detailsMap))
            ->toArray();

        $icd10Items = collect($this->form->procedures)
            ->flatMap(fn (array $procedure) => array_merge(
                $procedure['reasonReferences'] ?? [],
                $procedure['complicationDetails'] ?? []
            ))
            ->toArray();

        $this->loadIcd10Descriptions($icd10Items);
    }

    protected function loadClinicalImpressions(string $encounterUuid): void
    {
        $clinicalImpressions = Repository::clinicalImpression()->get($encounterUuid);

        if (!$clinicalImpressions) {
            return;
        }

        $allSupportingInfo = collect($clinicalImpressions)
            ->flatMap(fn (array $ci) => data_get($ci, 'supportingInfo', []))
            ->filter();

        $conditionUuids = collect($clinicalImpressions)
            ->flatMap(fn (array $clinicalImpression) => array_merge(
                collect(data_get($clinicalImpression, 'problems', []))
                    ->pluck('identifier.value')
                    ->toArray(),
                collect(data_get($clinicalImpression, 'findings', []))
                    ->filter(fn (array $finding) => data_get($finding, 'itemReference.identifier.type.coding.0.code') === 'condition')
                    ->pluck('itemReference.identifier.value')
                    ->toArray()
            ))
            ->filter()->unique()->values()->toArray();

        $observationUuids = collect($clinicalImpressions)
            ->flatMap(
                fn (array $clinicalImpression) => collect(data_get($clinicalImpression, 'findings', []))
                    ->filter(fn (array $finding) => data_get($finding, 'itemReference.identifier.type.coding.0.code') === 'observation')
                    ->pluck('itemReference.identifier.value')
                    ->toArray()
            )
            ->filter()->unique()->values()->toArray();

        $previousUuids = collect($clinicalImpressions)
            ->pluck('previous.identifier.value')
            ->filter()->unique()->values()->toArray();

        $uuidsByType = $allSupportingInfo
            ->groupBy(fn (array $item) => data_get($item, 'identifier.type.coding.0.code'))
            ->map(fn ($group) => $group->pluck('identifier.value')->filter()->unique()->values()->toArray());

        $detailsMap = array_merge(
            Repository::condition()->getDetailsMapByUuids($conditionUuids),
            Repository::observation()->getDetailsMapByUuids($observationUuids),
            Repository::clinicalImpression()->getDetailsMapByUuids($previousUuids),
            Repository::diagnosticReport()->getDetailsMapByUuids($uuidsByType->get('diagnostic_report', [])),
            Repository::procedure()->getDetailsMapByUuids($uuidsByType->get('procedure', [])),
            Repository::encounter()->getDetailsMapByUuids($uuidsByType->get('encounter', [])),
            Repository::episode()->getDetailsMapByUuids($uuidsByType->get('episode_of_care', [])),
        );

        $this->form->clinicalImpressions = collect($clinicalImpressions)
            ->map(fn (array $clinicalImpression) => Fhir::clinicalImpression()->fromFhir($clinicalImpression, $detailsMap))
            ->toArray();

        $icd10Items = collect($this->form->clinicalImpressions)
            ->flatMap(fn (array $clinicalImpression) => array_merge($clinicalImpression['problems'] ?? [], $clinicalImpression['findings'] ?? []))
            ->toArray();

        $this->loadIcd10Descriptions($icd10Items);
    }

    /**
     * Get details for selected encounter supporting info records.
     *
     * @param  array  $encounter
     * @return array
     */
    private function getEncounterSupportingInfoDetailsMap(array $encounter): array
    {
        $uuidsByType = collect(data_get($encounter, 'supporting_info', []))
            ->groupBy(fn (array $item) => data_get($item, 'identifier.type.coding.0.code'))
            ->map(fn ($group) => $group->pluck('identifier.value')->filter()->unique()->values()->toArray());

        return array_merge(
            Repository::condition()->getDetailsMapByUuids($uuidsByType->get('condition', [])),
            Repository::observation()->getDetailsMapByUuids($uuidsByType->get('observation', [])),
            Repository::diagnosticReport()->getDetailsMapByUuids($uuidsByType->get('diagnostic_report', []))
        );
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
}
