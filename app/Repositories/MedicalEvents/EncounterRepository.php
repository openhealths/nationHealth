<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Enums\ClinicalImpression\Status as ClinicalImpressionStatus;
use App\Enums\DetectedIssue\Status as DetectedIssueStatus;
use App\Enums\Device\Status as DeviceStatus;
use App\Enums\DeviceAssociation\Status as DeviceAssociationStatus;
use App\Enums\DeviceDispense\Status as DeviceDispenseStatus;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\JobStatus;
use App\Enums\Person\ConditionVerificationStatus;
use App\Enums\Person\DeviceRequestStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ImmunizationStatus;
use App\Enums\Person\MedicationRequestStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Person\ProcedureStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Enums\Specimen\Status as SpecimenStatus;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DetectedIssue;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\DeviceAssociation;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Encounter as EncounterSql;
use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @property Encounter $model
 */
class EncounterRepository extends BaseRepository
{
    public function findForStandaloneDocument(int $id, Person|Preperson $patient): Encounter
    {
        return Encounter::forPatient($patient)
            ->with(['episode', 'class', 'performer', 'hospitalization.dischargeDisposition', 'hospitalization.destination'])
            ->findOrFail($id);
    }

    /** Documents of the encounter and its treatment plans, scoped to the encounter's original patient owner. */
    public function linkedDocuments(Encounter $encounter, Person|Preperson $patient, User $user): array
    {
        if ($user->cannot('view', [$encounter, $patient])
            || ($encounter->person_id === null) === ($encounter->preperson_id === null)) {
            return [];
        }

        // A merged encounter retains its source owner; the displayed patient's FK must never replace it.
        $owner = $encounter->preperson_id !== null
            ? Preperson::findOrFail($encounter->preperson_id)
            : Person::findOrFail($encounter->person_id);
        $ownerColumn = $owner instanceof Preperson ? 'preperson_id' : 'person_id';
        $otherOwnerColumn = $owner instanceof Preperson ? 'person_id' : 'preperson_id';
        $routePrefix = $owner instanceof Preperson ? 'prepersons' : 'persons';
        $routeOwner = [$ownerColumn === 'person_id' ? 'person' : 'preperson' => $owner->id];
        $encounterReference = static fn (Builder $reference): Builder => $reference->whereValue($encounter->uuid);
        $incomingReferralUuid = $encounter->incomingReferral?->value;
        $referral = Str::isUuid($incomingReferralUuid ?? '')
            ? ServiceRequestRequest::forPatient($owner)->whereUuid($incomingReferralUuid)
                ->with(['basedOn', 'basedOnReferences'])->first() : null;
        $basis = $referral?->basedOnReferences->pluck('value') ?? collect();
        if ($referral?->basedOn !== null) {
            $basis->push($referral->basedOn->value);
        }
        $basis = $basis->filter(static fn ($uuid): bool => is_string($uuid) && Str::isUuid($uuid))->unique();
        $plans = $owner instanceof Person
            ? CarePlan::wherePersonId($owner->id)->where(static function (Builder $plan) use ($encounter, $encounterReference, $basis): void {
                $plan->whereEncounterId($encounter->id)->orWhereHas('encounterIdentifier', $encounterReference)
                    ->orWhereIn('uuid', $basis)->orWhereHas('activities', static fn (Builder $activity): Builder => $activity->whereIn('uuid', $basis));
            })->with('activities:id,care_plan_id,uuid')->get()
            : collect();
        $planReferences = $plans->pluck('uuid')->merge($plans->flatMap(static fn (CarePlan $plan): Collection => $plan->activities->pluck('uuid')))->filter()->unique();
        $planReference = static fn (Builder $reference): Builder => $reference->whereIn('value', $planReferences);

        $referrals = $user->can('search', ServiceRequestRequest::class)
            ? ServiceRequestRequest::forPatient($owner)->where(static fn (Builder $request): Builder => $request
                ->whereHas('context', $encounterReference)->orWhereHas('basedOn', $planReference)
                ->orWhereHas('basedOnReferences', $planReference)
                ->when($referral !== null, static fn (Builder $incoming): Builder => $incoming->orWhere('uuid', $referral->uuid)))->get()
            : collect();
        $prescriptions = $owner instanceof Person
            ? MedicationRequestRequest::wherePersonId($owner->id)->where(static fn (Builder $request): Builder => $request
                ->whereHas('context', $encounterReference)->orWhereHas('basedOn', $planReference)
                ->orWhereHas('references', static fn (Builder $reference): Builder => $planReference($reference)->where('medication_request_references.field', 'basedOn')))
                ->get()->filter(static fn (MedicationRequestRequest $request): bool => $user->can('view', $request))
            : collect();
        $devices = $owner instanceof Person && $user->can('viewAny', DeviceRequestRequest::class)
            ? DeviceRequestRequest::wherePersonId($owner->id)->where(static fn (Builder $request): Builder => $request
                ->whereHas('context', $encounterReference)->orWhereHas('basedOn', $planReference))->get()
            : collect();
        $compositions = Composition::where($ownerColumn, $owner->id)->whereNull($otherOwnerColumn)
            ->forEncounter($encounter->uuid)->get()->filter(static fn (Composition $composition): bool => $user->can('view', $composition));

        return [
            'referrals' => [
                'title' => __('patients.referrals'),
                'url' => $owner instanceof Person && $user->can('search', ServiceRequestRequest::class)
                    ? route('persons.referrals', [legalEntity(), 'person' => $owner->id]) : null,
                'records' => $referrals->map(static fn (ServiceRequestRequest $request): array => [
                    'id' => $request->uuid, 'number' => $request->requestNumber ?? $request->uuid,
                    'status' => ServiceRequestStatus::resolve($request->status)?->label() ?? $request->status, 'url' => null,
                ])->all(),
            ],
            'prescriptions' => [
                'title' => __('patients.prescriptions'),
                'url' => $owner instanceof Person && ($user->can('medication_request:read') || $user->can('medication_request_request:read'))
                    ? route('persons.medication-requests', [legalEntity(), 'person' => $owner->id]) : null,
                'records' => $prescriptions->map(static fn (MedicationRequestRequest $request): array => [
                    'id' => $request->uuid, 'number' => $request->requestNumber ?? $request->uuid,
                    'status' => MedicationRequestStatus::resolve($request->status)?->label() ?? $request->status,
                    'url' => route($request->resourceType === MedicationRequestRequest::TYPE_REQUEST ? 'persons.prescription-requests.view' : 'persons.medication-requests.view', [legalEntity(), 'person' => $owner->id, 'requestId' => $request->uuid]),
                ])->all(),
            ],
            'device-requests' => [
                'title' => __('device-requests.labels.patient_tab'),
                'url' => $owner instanceof Person && $user->can('viewAny', DeviceRequestRequest::class)
                    ? route('persons.device-requests', [legalEntity(), 'person' => $owner->id]) : null,
                'records' => $devices->map(static fn (DeviceRequestRequest $request): array => [
                    'id' => $request->uuid, 'number' => $request->requestNumber ?? $request->uuid,
                    'status' => DeviceRequestStatus::resolve($request->status)?->label() ?? $request->status,
                    'url' => route('persons.device-requests.view', [legalEntity(), 'person' => $owner->id, 'deviceRequest' => $request->uuid]),
                ])->all(),
            ],
            'care-plans' => [
                'title' => __('patients.care_plans'),
                'url' => $owner instanceof Person && $user->can('search', CarePlan::class)
                    ? route('persons.care-plans', [legalEntity(), 'person' => $owner->id]) : null,
                'records' => $plans->filter(static fn (CarePlan $plan): bool => $user->can('view', $plan))->map(static fn (CarePlan $plan): array => [
                    'id' => $plan->uuid, 'number' => $plan->displayTitle(), 'status' => CarePlanStatus::labelFor($plan->status),
                    'url' => route('care-plans.show', [legalEntity(), 'carePlan' => $plan->id]),
                ])->values()->all(),
            ],
            'compositions' => [
                'title' => __('patients.medical_reports'),
                'url' => $user->can('viewAny', Composition::class) ? route($routePrefix.'.compositions', [legalEntity(), ...$routeOwner]) : null,
                'records' => $compositions->map(static fn (Composition $composition): array => [
                    'id' => $composition->uuid, 'number' => $composition->uuid, 'status' => $composition->status->label(), 'url' => null,
                ])->values()->all(),
            ],
        ];
    }

    /** @return Collection<int, Condition> */
    public function primaryDiagnosisConditions(Encounter $encounter): Collection
    {
        $encounter->loadMissing(['diagnoses.role.coding']);
        $primaryDiagnoses = $encounter->diagnoses->filter(
            static fn (EncounterDiagnose $diagnosis): bool => $diagnosis->role?->coding->contains('code', 'primary') === true
        );
        if ($primaryDiagnoses->count() !== 1) {
            return collect();
        }

        $diagnosis = $primaryDiagnoses->first();
        if ($diagnosis->relationLoaded('conditionModel')) {
            $condition = $diagnosis->getRelation('conditionModel');
        } else {
            $diagnosis->loadMissing('condition');
            $conditionUuid = $diagnosis->getRelation('condition')?->value;
            if (!is_string($conditionUuid) || !Str::isUuid($conditionUuid)) {
                return collect();
            }

            // Identifier values are varchar; resolve the UUID without a mixed-type join.
            $condition = Condition::with('code.coding')
                ->whereUuid($conditionUuid)
                ->wherePersonId($encounter->person_id)
                ->first();
        }
        if (!$condition instanceof Condition || (int) $condition->personId !== (int) $encounter->person_id) {
            return collect();
        }
        $condition->loadMissing('code.coding');
        $diagnosis->setRelation('conditionModel', $condition);

        return collect([$condition]);
    }

    /**
     * Create encounter in DB for the patient (person or preperson) with related data.
     *
     * @param  array  $data
     * @param  Person|Preperson  $patient
     * @return false|int
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient): false|int
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        return DB::transaction(function () use ($data, $ownerColumn, $ownerId) {
            $visit = Repository::identifier()->store($data['visit']['identifier']['value']);
            Repository::codeableConcept()->attach($visit, $data['visit']);

            $episode = Repository::identifier()->store($data['episode']['identifier']['value']);
            Repository::codeableConcept()->attach($episode, $data['episode']);

            $class = Repository::coding()->store($data['class']);

            $type = Repository::codeableConcept()->store($data['type']);

            if (isset($data['priority'])) {
                $priority = Repository::codeableConcept()->store($data['priority']);
            }

            $performer = Repository::identifier()->store($data['performer']['identifier']['value']);
            Repository::codeableConcept()->attach($performer, $data['performer']);

            if (isset($data['division'])) {
                $division = Repository::identifier()->store($data['division']['identifier']['value']);
                Repository::codeableConcept()->attach($division, $data['division']);
            }

            if (isset($data['incomingReferral'])) {
                $incomingReferral = Repository::identifier()->store(
                    $data['incomingReferral']['identifier']['value'],
                    $data['incomingReferral']['display_value'] ?? ($data['incomingReferral']['displayValue'] ?? null)
                );
                Repository::codeableConcept()->attach($incomingReferral, $data['incomingReferral']);
            }

            $encounter = $this->model->create([
                $ownerColumn => $ownerId,
                'uuid' => $data['uuid'] ?? $data['id'],
                'status' => $data['status'],
                'visit_id' => $visit->id,
                'episode_id' => $episode->id,
                'class_id' => $class->id,
                'type_id' => $type->id,
                'priority_id' => $priority->id ?? null,
                'performer_id' => $performer->id,
                'division_id' => $division->id ?? null,
                'incoming_referral_id' => $incomingReferral->id ?? null,
                'prescriptions' => $data['prescriptions'] ?? null
            ]);

            $encounter->period()->create([
                'start' => $data['period']['start'],
                'end' => $data['period']['end']
            ]);

            if (!empty($data['paperReferral'])) {
                Repository::paperReferral()->store($data['paperReferral'], $encounter);
            }

            if (!empty($data['reasons'])) {
                $encounter->reasons()->attach(
                    collect($data['reasons'])
                        ->map(static fn (array $reasonData) => Repository::codeableConcept()->store($reasonData)->id)
                        ->all()
                );
            }

            foreach ($data['diagnoses'] as $diagnoseData) {
                $condition = Repository::identifier()->store($diagnoseData['condition']['identifier']['value']);
                Repository::codeableConcept()->attach($condition, $diagnoseData['condition']);

                $role = Repository::codeableConcept()->store($diagnoseData['role']);

                $encounter->diagnoses()->create([
                    'condition_id' => $condition->id,
                    'role_id' => $role->id,
                    'rank' => $diagnoseData['rank'] ?? null
                ]);
            }

            if (!empty($data['actions'])) {
                $encounter->actions()->attach(
                    collect($data['actions'])
                        ->map(static fn (array $actionData) => Repository::codeableConcept()->store($actionData)->id)
                        ->all()
                );
            }

            if (isset($data['actionReferences'])) {
                foreach ($data['actionReferences'] as $actionReference) {
                    $identifier = Repository::identifier()->store(
                        $actionReference['identifier']['value'],
                        $actionReference['display_value'] ?? null
                    );
                    Repository::codeableConcept()->attach($identifier, $actionReference);
                    $encounter->actionReferences()->attach($identifier->id);
                }
            }

            if (isset($data['participant'])) {
                foreach ($data['participant'] as $participant) {
                    $identifier = Repository::identifier()->store(
                        $participant['identifier']['value'],
                        $participant['display_value'] ?? null
                    );
                    Repository::codeableConcept()->attach($identifier, $participant);
                    $encounter->participants()->attach($identifier->id);
                }
            }

            if (isset($data['supportingInfo'])) {
                foreach ($data['supportingInfo'] as $supporting) {
                    $identifier = Repository::identifier()->store(
                        $supporting['identifier']['value'],
                        $supporting['display_value'] ?? null
                    );
                    Repository::codeableConcept()->attach($identifier, $supporting);
                    $encounter->supportingInfo()->attach($identifier->id);
                }
            }

            if (isset($data['hospitalization'])) {
                $hospitalization = $data['hospitalization'];

                if (isset($hospitalization['destination'])) {
                    $destination = Repository::identifier()->store($hospitalization['destination']['identifier']['value']);
                    Repository::codeableConcept()->attach($destination, $hospitalization['destination']);
                }

                $encounter->hospitalization()->create([
                    'pre_admission_identifier' => $hospitalization['preAdmissionIdentifier'] ?? null,
                    'admit_source_id' => isset($hospitalization['admitSource'])
                        ? Repository::coding()->store($hospitalization['admitSource']['coding'][0])->id
                        : null,
                    're_admission_id' => isset($hospitalization['reAdmission'])
                        ? Repository::coding()->store($hospitalization['reAdmission']['coding'][0])->id
                        : null,
                    'destination_id' => $destination->id ?? null,
                    'discharge_disposition_id' => isset($hospitalization['dischargeDisposition'])
                        ? Repository::coding()->store($hospitalization['dischargeDisposition']['coding'][0])->id
                        : null,
                    'discharge_department_id' => isset($hospitalization['dischargeDepartment'])
                        ? Repository::coding()->store($hospitalization['dischargeDepartment']['coding'][0])->id
                        : null
                ]);
            }

            return $encounter->id;
        });
    }

    /**
     * The field each kind of record of the encounter package carries its status in, and the cancelled status.
     *
     * @var array
     */
    private const array CANCELLED_STATUSES = [
        DeviceDispense::class => ['status', DeviceDispenseStatus::ENTERED_IN_ERROR],
        Condition::class => ['verification_status', ConditionVerificationStatus::ENTERED_IN_ERROR],
        Observation::class => ['status', ObservationStatus::ENTERED_IN_ERROR],
        Immunization::class => ['status', ImmunizationStatus::ENTERED_IN_ERROR],
        DiagnosticReport::class => ['status', DiagnosticReportStatus::ENTERED_IN_ERROR],
        Procedure::class => ['status', ProcedureStatus::ENTERED_IN_ERROR],
        ClinicalImpression::class => ['status', ClinicalImpressionStatus::ENTERED_IN_ERROR],
        Device::class => ['status', DeviceStatus::ENTERED_IN_ERROR],
        DeviceAssociation::class => ['status', DeviceAssociationStatus::ENTERED_IN_ERROR],
        DetectedIssue::class => ['status', DetectedIssueStatus::ENTERED_IN_ERROR],
        Specimen::class => ['status', SpecimenStatus::ENTERED_IN_ERROR]
    ];

    /**
     * The same for the records that may be marked as entered in error on their own, keyed by the package section
     * they arrive in. Conditions are left out on purpose: a diagnosis only goes with the whole encounter.
     *
     * @var array
     */
    private const array SEPARATELY_CANCELLED_STATUSES = [
        'deviceDispenses' => [DeviceDispense::class, 'status', DeviceDispenseStatus::ENTERED_IN_ERROR],
        'observations' => [Observation::class, 'status', ObservationStatus::ENTERED_IN_ERROR],
        'immunizations' => [Immunization::class, 'status', ImmunizationStatus::ENTERED_IN_ERROR],
        'diagnosticReports' => [DiagnosticReport::class, 'status', DiagnosticReportStatus::ENTERED_IN_ERROR],
        'procedures' => [Procedure::class, 'status', ProcedureStatus::ENTERED_IN_ERROR],
        'clinicalImpressions' => [ClinicalImpression::class, 'status', ClinicalImpressionStatus::ENTERED_IN_ERROR],
        'devices' => [Device::class, 'status', DeviceStatus::ENTERED_IN_ERROR],
        'deviceAssociations' => [DeviceAssociation::class, 'status', DeviceAssociationStatus::ENTERED_IN_ERROR],
        'detectedIssues' => [DetectedIssue::class, 'status', DetectedIssueStatus::ENTERED_IN_ERROR],
        'specimens' => [Specimen::class, 'status', SpecimenStatus::ENTERED_IN_ERROR]
    ];

    /**
     * Package sections eHealth only accepts as entered in error together: a device cannot be marked while an
     * association or a detected issue of the same encounter stays active, and neither of those two can be
     * marked while a device of that encounter stays active.
     */
    private const array DEVICE_GROUP_SECTIONS = ['devices', 'deviceAssociations', 'detectedIssues'];

    /**
     * The relation each kind of record of the package holds the identifier of its encounter in.
     */
    private const array ENCOUNTER_RELATIONS = [
        'deviceDispenses' => 'encounter',
        'observations' => 'context',
        'immunizations' => 'context',
        'diagnosticReports' => 'encounter',
        'procedures' => 'encounter',
        'clinicalImpressions' => 'encounter',
        'devices' => 'context',
        'deviceAssociations' => 'context',
        'detectedIssues' => 'encounter',
        'specimens' => 'context'
    ];

    /**
     * Get the eHealth ID of the encounter a record of the package was created in, or `null` when the record is not
     * stored locally, which is the case for one found through a search in eHealth and never synchronised.
     *
     * @param  string  $section  Package section the record belongs to, e.g. `observations`
     * @param  string  $recordId  eHealth ID of the record
     * @param  Person|Preperson  $patient
     * @return string|null
     */
    public function encounterIdOfRecord(string $section, string $recordId, Person|Preperson $patient): ?string
    {
        $relation = self::ENCOUNTER_RELATIONS[$section] ?? null;

        if ($relation === null) {
            return null;
        }

        [$model] = self::SEPARATELY_CANCELLED_STATUSES[$section];

        return $model::forPatient($patient)
            ->with($relation)
            ->whereUuid($recordId)
            ->first()
            ?->{$relation}
            ?->value;
    }

    /**
     * Widen a pick of records to the whole device group as soon as it touches any of it, so that a partial
     * group cannot reach eHealth no matter which page the pick was made on. The records the encounter still
     * holds are read here rather than taken from the caller, which may have only part of the package at hand.
     *
     * @param  string  $encounterId  eHealth ID of the encounter the records belong to
     * @param  array  $recordIds  eHealth IDs of the picked records, keyed by package section
     * @return array
     */
    public function withWholeDeviceGroup(string $encounterId, array $recordIds): array
    {
        if (array_intersect_key($recordIds, array_flip(self::DEVICE_GROUP_SECTIONS)) === []) {
            return $recordIds;
        }

        foreach (self::DEVICE_GROUP_SECTIONS as $section) {
            [$model, $statusField, $status] = self::SEPARATELY_CANCELLED_STATUSES[$section];

            $recordIds[$section] = $model::forEncounter($encounterId)
                ->where($statusField, '!=', $status->value)
                ->pluck('uuid')
                ->all();
        }

        return array_filter($recordIds);
    }

    /**
     * Mark the given records of the encounter package as entered in error, leaving the encounter and every
     * record left out as they are.
     *
     * @param  string  $encounterId  eHealth ID of the encounter the records belong to
     * @param  array  $recordIds  eHealth IDs of the records, keyed by package section
     * @param  string  $explanatoryLetter
     * @return void
     * @throws Throwable
     */
    public function markRecordsAsEnteredInError(
        string $encounterId,
        array $recordIds,
        string $explanatoryLetter
    ): void {
        DB::transaction(static function () use ($encounterId, $recordIds, $explanatoryLetter): void {
            foreach (self::SEPARATELY_CANCELLED_STATUSES as $packageKey => [$model, $statusField, $status]) {
                $uuids = $recordIds[$packageKey] ?? [];

                if ($uuids === []) {
                    continue;
                }

                $model::forEncounter($encounterId)
                    ->whereIn('uuid', $uuids)
                    ->where($statusField, '!=', $status->value)
                    ->update([
                        $statusField => $status->value,
                        'explanatory_letter' => $explanatoryLetter
                    ]);
            }
        });
    }

    /**
     * eHealth IDs of the package records already marked as entered in error, keyed by package section.
     *
     * @param  string  $encounterId  eHealth ID of the encounter
     * @return array
     */
    public function cancelledRecordIds(string $encounterId): array
    {
        $cancelled = [];

        foreach (self::SEPARATELY_CANCELLED_STATUSES as $packageKey => [$model, $statusField, $status]) {
            $cancelled[$packageKey] = $model::forEncounter($encounterId)
                ->where($statusField, $status->value)
                ->pluck('uuid')
                ->toArray();
        }

        return $cancelled;
    }

    /**
     * Mark the encounter and every record created alongside it as entered in error.
     *
     * @param  Encounter  $encounter
     * @param  array  $cancellationReason
     * @param  string  $explanatoryLetter
     * @return void
     * @throws Throwable
     */
    public function markAsEnteredInError(
        Encounter $encounter,
        array $cancellationReason,
        string $explanatoryLetter
    ): void {
        DB::transaction(static function () use ($encounter, $cancellationReason, $explanatoryLetter): void {
            $encounter->loadMissing(['cancellationReason.coding']);

            $cancellationReasonModel = $encounter->cancellationReason
                ? Repository::codeableConcept()->update($encounter->cancellationReason, $cancellationReason)
                : Repository::codeableConcept()->store($cancellationReason);

            $encounter->update([
                'status' => EncounterStatus::ENTERED_IN_ERROR->value,
                'cancellation_reason_id' => $cancellationReasonModel->id,
                'explanatory_letter' => $explanatoryLetter
            ]);

            foreach (self::CANCELLED_STATUSES as $model => [$statusField, $cancelledStatus]) {
                $model::forEncounter($encounter->uuid)
                    ->where($statusField, '!=', $cancelledStatus->value)
                    ->update([
                        $statusField => $cancelledStatus->value,
                        'explanatory_letter' => $explanatoryLetter
                    ]);
            }
        });
    }

    /**
     * Fetch the complete remote package before rebuilding signed cancellation content.
     * Every page is validated before it can be synchronized; a later failure leaves the package uncancelled.
     */
    public function synchronizeCancellationPackage(Encounter $encounter, Person|Preperson $patient): array
    {
        $remoteEncounter = EHealth::encounter()->getById($patient->uuid, $encounter->uuid)->validate();
        if (($remoteEncounter['uuid'] ?? null) !== $encounter->uuid) {
            throw new RuntimeException('Encounter detail returned a different resource.');
        }

        $sections = [
            'condition' => 'encounter_id',
            'observation' => 'encounter_id',
            'immunization' => 'encounter_id',
            'diagnosticReport' => 'encounter_id',
            'procedure' => 'encounter_id',
            'clinicalImpression' => 'encounter_id',
            'device' => 'encounter_id',
            'deviceAssociation' => 'encounter_id',
            'detectedIssue' => 'encounter_id',
            'specimen' => 'encounter',
            'deviceDispense' => 'encounter',
        ];
        $resources = [];
        foreach ($sections as $name => $filter) {
            $resources[$name] = $this->cancellationRecords($name, $filter, $patient->uuid, $encounter->uuid);
        }

        // A diagnosis can point to a condition recorded before this encounter.
        $knownConditions = array_column($resources['condition'], 'uuid');
        foreach ($remoteEncounter['diagnoses'] ?? [] as $diagnosis) {
            $uuid = data_get($diagnosis, 'condition.identifier.value');
            if (is_string($uuid) && !in_array($uuid, $knownConditions, true)) {
                $condition = EHealth::condition()->getById($patient->uuid, $uuid)->validate();
                if (($condition['uuid'] ?? null) !== $uuid) {
                    throw new RuntimeException('Diagnosis condition detail returned a different resource.');
                }
                $resources['condition'][] = $condition;
                $knownConditions[] = $uuid;
            }
        }

        DB::transaction(static function () use ($patient, $remoteEncounter, $resources): void {
            Repository::encounter()->sync($patient, [$remoteEncounter]);
            foreach ($resources as $name => $records) {
                Repository::$name()->sync($patient, $records);
            }
        });

        return ['encounter' => $remoteEncounter, 'resources' => $resources];
    }

    /** Recover an accepted cancellation whose HTTP receipt was lost, using validated remote resources. */
    public function reconcileCancellation(EhealthLink $submission, Person|Preperson $patient): bool
    {
        $encounter = $submission->linkable;
        if (!$encounter instanceof Encounter || !Encounter::forPatient($patient)->whereKey($encounter->id)->exists()) {
            throw new RuntimeException('Cancellation does not belong to the current patient.');
        }
        $snapshot = $this->synchronizeCancellationPackage($encounter, $patient);
        $selected = $submission->job->request_data['selectedRecords'];
        if ($selected === [] && $snapshot['encounter']['status'] !== EncounterStatus::ENTERED_IN_ERROR->value) {
            return false;
        }
        $sections = [
            'condition' => 'conditions', 'observation' => 'observations', 'immunization' => 'immunizations',
            'diagnosticReport' => 'diagnosticReports', 'procedure' => 'procedures', 'clinicalImpression' => 'clinicalImpressions',
            'device' => 'devices', 'deviceAssociation' => 'deviceAssociations', 'detectedIssue' => 'detectedIssues',
            'specimen' => 'specimens', 'deviceDispense' => 'deviceDispenses',
        ];
        foreach ($sections as $name => $section) {
            $records = collect($snapshot['resources'][$name])->keyBy('uuid');
            $uuids = $selected === [] ? $records->keys()->all() : ($selected[$section] ?? []);
            foreach ($uuids as $uuid) {
                $record = $records->get($uuid);
                // A diagnosis from an earlier encounter must retain its original verification status.
                if ($name === 'condition' && data_get($record, 'context.identifier.value') !== $encounter->uuid) {
                    continue;
                }
                $status = $name === 'condition' ? 'verification_status' : 'status';
                if ($record === null || ($record[$status] ?? null) !== 'entered_in_error') {
                    return false;
                }
            }
        }

        // This is a local recovery result backed by the resource reads above, not an invented remote job response.
        return $this->finishCancellation($submission, ['status' => 'processed', 'verified_by' => 'remote_resources']);
    }

    /** @return Collection<int, EhealthLink> */
    public function patientCancellationsByIds(Person|Preperson $patient, array $ids): Collection
    {
        return EhealthLink::with(['job', 'linkable'])
            ->whereIn('id', $ids)
            ->whereHasMorph('linkable', [Encounter::class], static fn ($query) => $query->forPatient($patient))
            ->whereHas('job', static fn ($query) => $query->where('request_data->operation', 'cancelEncounter'))
            ->get();
    }

    /** Read all pages using the endpoint's actual encounter filter. */
    private function cancellationRecords(string $name, string $filter, string $patientUuid, string $encounterUuid): array
    {
        $records = [];
        $page = 1;
        $pageSize = (int) config('ehealth.api.page_size', 300);
        $expectedPaging = null;
        do {
            $response = EHealth::$name()->getBySearchParams($patientUuid, [
                $filter => $encounterUuid, 'page' => $page, 'page_size' => $pageSize,
            ]);
            $items = $response->validate();
            if (!is_array($items) || !array_is_list($items)) {
                throw new RuntimeException('Encounter package search did not return a collection.');
            }
            $paging = $response->getPaging();
            Validator::make($paging, [
                    'page_number' => ['required', 'integer', 'min:1', 'in:'.$page],
                    'page_size' => ['required', 'integer', 'min:1'],
                    'total_pages' => ['required', 'integer', 'min:0'],
                    'total_entries' => ['required', 'integer', 'min:0'],
            ])->validate();
            $totals = [(int) $paging['total_entries'], (int) $paging['total_pages']];
            if ($expectedPaging !== null && $totals !== $expectedPaging) {
                throw new RuntimeException('Encounter package changed while reading its pages.');
            }
            $expectedPaging = $totals;
            foreach ($items as $item) {
                $context = in_array($name, ['condition', 'observation', 'immunization', 'device', 'deviceAssociation', 'specimen'], true)
                    ? 'context' : 'encounter';
                if (data_get($item, $context.'.identifier.value') !== $encounterUuid) {
                    throw new RuntimeException('Encounter package search returned a record from another encounter.');
                }
                $uuid = $item['uuid'];
                if (isset($records[$uuid])) {
                    throw new RuntimeException('Encounter package search repeated a record across pages.');
                }
                $records[$uuid] = $item;
            }
            $hasNext = $page < (int) $paging['total_pages'];
            if ($hasNext && $items === []) {
                throw new RuntimeException('Encounter package search returned an incomplete page.');
            }
            $page++;
        } while ($hasNext);

        if (count($records) !== $expectedPaging[0]) {
            throw new RuntimeException('Encounter package search returned an incomplete collection.');
        }

        return array_values($records);
    }

    /** A durable duplicate guard survives refreshes and an uncertain HTTP outcome. */
    public function pendingCancellation(Encounter $encounter): ?EhealthLink
    {
        return $encounter->ehealthLinks()
            ->whereIn('status', [JobStatus::PENDING->value, JobStatus::PROCESSING->value])
            ->whereHas('job', static fn ($query) => $query->where('request_data->operation', 'cancelEncounter'))
            ->latest('id')->first();
    }

    /** Lock the encounter so simultaneous tabs cannot submit the same cancellation twice. */
    public function startCancellation(Encounter $encounter, array $request): ?EhealthLink
    {
        return DB::transaction(static function () use ($encounter, $request): ?EhealthLink {
            $locked = Encounter::whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            if (Repository::encounter()->pendingCancellation($locked) !== null) {
                return null;
            }

            return RemoteEHealthLinksProcessing::startSubmission($locked, $request);
        });
    }

    /** @return Collection<int, EhealthLink> */
    public function pendingPatientCancellations(Person|Preperson $patient): Collection
    {
        return EhealthLink::with(['job', 'linkable'])
            ->whereIn('status', [JobStatus::PENDING->value, JobStatus::PROCESSING->value])
            ->whereHasMorph('linkable', [Encounter::class], static fn ($query) => $query->forPatient($patient))
            ->whereHas('job', static fn ($query) => $query->where('request_data->operation', 'cancelEncounter'))
            ->get();
    }

    /** Apply cancellation exactly once, after a final validated eHealth job result. */
    public function finishCancellation(EhealthLink $submission, array $result): bool
    {
        Validator::make($result, [
            'status' => ['required', 'string', 'in:pending,processing,accepted,queued,processed,failed'],
            'status_code' => ['nullable', 'integer'],
            'error' => ['nullable'],
        ])->validate();
        if (in_array(strtolower($result['status']), ['pending', 'processing', 'accepted', 'queued'], true)) {
            return false;
        }

        return DB::transaction(static function () use ($submission, $result): bool {
            $link = EhealthLink::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if (!in_array($link->status, [JobStatus::PENDING->value, JobStatus::PROCESSING->value], true)) {
                return false;
            }
            $request = $link->job->request_data;
            $encounter = $link->linkable;
            if (!$encounter instanceof Encounter || ($request['operation'] ?? null) !== 'cancelEncounter') {
                throw new RuntimeException('Invalid encounter cancellation submission.');
            }
            $succeeded = strtolower($result['status']) === 'processed'
                && !isset($result['error'])
                && (!isset($result['status_code']) || in_array($result['status_code'], [200, 201, 204], true));
            if ($succeeded) {
                if ($request['selectedRecords'] === []) {
                    Repository::encounter()->markAsEnteredInError(
                        $encounter,
                        $request['cancellationReasonConcept'],
                        $request['explanatoryLetter']
                    );
                } else {
                    Repository::encounter()->markRecordsAsEnteredInError(
                        $encounter->uuid,
                        $request['selectedRecords'],
                        $request['explanatoryLetter']
                    );
                }
            }
            $status = $succeeded ? JobStatus::PROCESSED->value : JobStatus::FAILED->value;
            $link->job()->update(['status' => $status, 'response_data' => $result]);
            $link->update([
                'status' => $status,
                'error' => $succeeded ? null : ($result['error'] ?? ['message' => __('encounters.messages.cancel_package_request_error')]),
                'error_code' => $result['status_code'] ?? null,
            ]);

            return true;
        });
    }

    /**
     * Delete a draft encounter with the records of its package and the draft episode opened along with it, together
     * with every row they refer to: identifiers, codeable concepts, codings, quantities, periods and eHealth jobs.
     * The draft never reached eHealth, so none of these records exists there.
     *
     * @param  Encounter  $encounter
     * @return void
     * @throws Throwable
     */
    public function deleteDraft(Encounter $encounter): void
    {
        DB::transaction(function () use ($encounter): void {
            $rows = collect([
                Condition::forEncounter($encounter->uuid)->withAllRelations()->get(),
                Immunization::forEncounter($encounter->uuid)->withAllRelations()->get(),
                DiagnosticReport::forEncounter($encounter->uuid)->withAllRelations()->get(),
                Observation::forEncounter($encounter->uuid)->withAllRelations()->get(),
                Procedure::forEncounter($encounter->uuid)->withAllRelations()->get(),
                Device::forEncounter($encounter->uuid)->withAllRelations()->get(),
                DeviceAssociation::forEncounter($encounter->uuid)->withAllRelations()->get(),
                DetectedIssue::forEncounter($encounter->uuid)->withAllRelations()->get(),
                DeviceDispense::forEncounter($encounter->uuid)->withAllRelations()->get(),
                Specimen::forEncounter($encounter->uuid)->withAllRelations()->get(),
                ClinicalImpression::forEncounter($encounter->uuid)->withAllRelations()->get(),
                Episode::whereEncounterId($encounter->id)->whereStatus(EpisodeStatus::DRAFT)->withRelationships()->get()
            ])
                ->collapse()
                ->push(
                    Encounter::withRelationships()
                        ->with(['ehealthLinks.job', 'ehealthLinks.processingData'])
                        ->findOrFail($encounter->id)
                );

            // An episode eHealth accepted while rejecting the package stays, only its link to the draft goes
            Episode::whereEncounterId($encounter->id)->update(['encounter_id' => null]);

            $this->deleteWithLoadedRows($rows);
        });
    }

    /**
     * Delete the given records together with every row loaded under them, each of which belongs to its record alone.
     * The rows go level by level, from the records down to the rows they refer to: a row refers to the level below
     * by a foreign key, while the level below refers back to it only by a cascading foreign key or by a morph link
     * without one.
     *
     * @param  Collection  $rows  Records loaded with the relations that are deleted along with them
     * @return void
     */
    public function deleteWithLoadedRows(Collection $rows): void
    {
        while ($rows->isNotEmpty()) {
            foreach ($rows->groupBy(static fn (Model $row): string => $row::class) as $levelRows) {
                new EloquentCollection($levelRows)->toQuery()->delete();
            }

            $rows = $rows->flatMap(static fn (Model $row): array => array_values(Arr::except($row->getRelations(), 'pivot')))
                ->flatMap(static fn (mixed $related): Collection => Collection::wrap($related))
                ->filter();
        }
    }

    /**
     * Get the condition UUIDs of the encounters' primary diagnoses.
     *
     * @param  array  $uuids  Encounter UUIDs
     * @return array
     */
    public function primaryConditionUuids(array $uuids): array
    {
        return EncounterSql::whereIn('uuid', $uuids)
            ->with('diagnoses')
            ->get()
            ->map($this->primaryConditionUuid(...))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the encounter for the clinical impression based on the provided UUID to display the selected supporting info.
     *
     * @param  array  $uuids
     * @return array
     */
    public function getDetailsMapByUuids(array $uuids): array
    {
        $encounters = EncounterSql::whereIn('uuid', $uuids)
            ->with(['period', 'diagnoses'])
            ->get();

        // An encounter is named by its primary diagnosis, whose code is kept in the condition
        $conditions = Condition::whereIn('uuid', $encounters->map($this->primaryConditionUuid(...))->filter()->values())
            ->with('code.coding')
            ->get()
            ->keyBy('uuid');

        return $encounters
            ->mapWithKeys(function (EncounterSql $encounter) use ($conditions): array {
                $coding = $conditions->get($this->primaryConditionUuid($encounter))?->code?->coding->first();

                return [
                    $encounter->uuid => [
                        'ehealthInsertedAt' => convertToAppDateFormat($encounter->period?->start),
                        'codeCode' => $coding?->code,
                        'codeSystem' => $coding?->system,
                        'type' => 'encounter'
                    ]
                ];
            })
            ->toArray();
    }

    /**
     * Get encounter data that is related to the patient (person or preperson).
     * Every record carries a readable `name` built from the start of its period, its class and its first action,
     * so it can label a filter option. When the patient access flag is given, only the encounters the user may read
     * are returned.
     *
     * @param  Person|Preperson  $patient
     * @param  bool|null  $hasPatientAccess
     * @return array
     */
    public function getByPersonId(Person|Preperson $patient, ?bool $hasPatientAccess = null): array
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        $dictionaries = dictionary()->basics()
            ->getMultipleFormatted(['eHealth/encounter_classes', 'eHealth/ICPC2/actions'])
            ->toArray();

        $query = $hasPatientAccess === null
            ? $this->model->where($ownerColumn, $ownerId)
            : $this->model->readableFor($patient, $hasPatientAccess);

        return $query->withRelationships()
            ->get()
            ->map(static function (Encounter $encounter) use ($dictionaries): array {
                $data = $encounter->toArray();
                $actionCode = data_get($data, 'actions.0.coding.0.code');
                $actionName = $dictionaries['eHealth/ICPC2/actions'][(string) $actionCode] ?? null;

                $label = collect([
                    data_get($data, 'period.start'),
                    $dictionaries['eHealth/encounter_classes'][(string) data_get($data, 'class.code')] ?? null,
                    $actionName ? "$actionCode $actionName" : null
                ])->filter()->implode(' · ');

                $data['name'] = $label ?: $encounter->uuid;

                return $data;
            })
            ->toArray();
    }

    /**
     * Sync encounter data and related data by comparing existing data with API data.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData) {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingEncounters = $this->model->whereIn('uuid', $apiUuids)
                ->withRelationships()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingEncounters->get($data['uuid']);

                $class = $this->syncCoding($existing, $data['class'], 'class');
                $type = $this->syncCodeableConcept($existing, $data['type'], 'type');
                $priority = $this->syncCodeableConcept($existing, $data['priority'] ?? null, 'priority');
                $cancellationReason = $this->syncCodeableConcept(
                    $existing,
                    $data['cancellation_reason'] ?? null,
                    'cancellationReason'
                );
                $performerSpeciality = $this->syncCodeableConcept(
                    $existing,
                    $data['performer_speciality'] ?? null,
                    'performerSpeciality'
                );

                $visit = $this->syncIdentifier($existing, $data['visit'] ?? null, 'visit');
                $episode = $this->syncIdentifier($existing, $data['episode'], 'episode');
                $incomingReferralData = $data['incoming_referral'] ?? null;

                if ($incomingReferralData && $existing?->incomingReferral?->displayValue) {
                    $incomingReferralData['display_value'] = $existing->incomingReferral->displayValue;
                }

                $incomingReferral = $this->syncIdentifier(
                    $existing,
                    $incomingReferralData,
                    'incomingReferral'
                );
                $originEpisode = $this->syncIdentifier(
                    $existing,
                    $data['origin_episode'] ?? null,
                    'originEpisode'
                );
                $performer = $this->syncIdentifier($existing, $data['performer'] ?? null, 'performer');
                $division = $this->syncIdentifier($existing, $data['division'] ?? null, 'division');

                $encounterData = [
                    $ownerColumn => $ownerId,
                    'status' => $data['status'],
                    'cancellation_reason_id' => $cancellationReason?->id,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'prescriptions' => $data['prescriptions'] ?? null,
                    'class_id' => $class->id,
                    'type_id' => $type->id,
                    'priority_id' => $priority?->id,
                    'performer_speciality_id' => $performerSpeciality?->id,
                    'visit_id' => $visit?->id,
                    'episode_id' => $episode->id,
                    'incoming_referral_id' => $incomingReferral?->id,
                    'origin_episode_id' => $originEpisode?->id,
                    'performer_id' => $performer?->id,
                    'division_id' => $division?->id,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null
                ];

                if ($existing) {
                    $existing->update($encounterData);
                    $encounter = $existing;
                } else {
                    $encounter = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $encounterData)
                    );
                }

                Repository::period()->sync($encounter, $data['period']);

                $this->syncPivot(
                    $encounter,
                    'reasons',
                    $this->syncCodeableConcepts($existing, $data['reasons'] ?? null, 'reasons')
                );
                $this->syncPivot(
                    $encounter,
                    'actions',
                    $this->syncCodeableConcepts($existing, $data['actions'] ?? null, 'actions')
                );
                $this->syncPivot(
                    $encounter,
                    'actionReferences',
                    $this->syncIdentifiers($existing, $data['action_references'] ?? null, 'actionReferences')
                );
                $this->syncPivot(
                    $encounter,
                    'participants',
                    $this->syncIdentifiers($existing, $data['participant'] ?? null, 'participants')
                );
                $this->syncPivot(
                    $encounter,
                    'supportingInfo',
                    $this->syncIdentifiers($existing, $data['supporting_info'] ?? null, 'supportingInfo')
                );

                $this->syncDiagnoses($encounter, $data['diagnoses'] ?? []);
                $this->syncHospitalization($encounter, $data['hospitalization'] ?? null);

                if (!empty($data['paper_referral'])) {
                    Repository::paperReferral()->sync($data['paper_referral'], $encounter, $existing);
                } else {
                    $encounter->paperReferral?->delete();
                }
            }
        });
    }

    /**
     * Sync encounters of the patient summary, which carries only part of the encounter fields. An encounter stored
     * before keeps every field the summary leaves out.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function syncShort(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData): void {
            $apiUuids = collect($validatedData)->pluck('uuid')->toArray();

            $existingEncounters = $this->model->whereIn('uuid', $apiUuids)
                ->withRelationships()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingEncounters->get($data['uuid']);

                $class = $this->syncCoding($existing, $data['class'], 'class');
                $type = $this->syncCodeableConcept($existing, $data['type'], 'type');
                $performerSpeciality = $this->syncCodeableConcept(
                    $existing,
                    $data['performer_speciality'],
                    'performerSpeciality'
                );
                $episode = $this->syncIdentifier($existing, $data['episode'], 'episode');

                $encounterData = [
                    $ownerColumn => $ownerId,
                    'status' => $data['status'],
                    'class_id' => $class->id,
                    'type_id' => $type->id,
                    'performer_speciality_id' => $performerSpeciality->id,
                    'episode_id' => $episode->id
                ];

                if ($existing) {
                    $existing->update($encounterData);
                    $encounter = $existing;
                } else {
                    $encounter = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $encounterData)
                    );
                }

                Repository::period()->sync($encounter, $data['period']);
            }
        });
    }

    /**
     * Sync encounter diagnoses (HasMany) with nested condition identifiers and role codeable concepts.
     *
     * @param  Encounter  $encounter
     * @param  array  $diagnosesData
     * @return void
     */
    protected function syncDiagnoses(Encounter $encounter, array $diagnosesData): void
    {
        $existingDiagnoses = $encounter->relationLoaded('diagnoses') ? $encounter->diagnoses : collect();

        if (empty($diagnosesData)) {
            $existingDiagnoses->each(fn (EncounterDiagnose $diagnose) => $diagnose->delete());

            return;
        }

        $existingByConditionValue = $existingDiagnoses->keyBy(
            fn (EncounterDiagnose $diagnose) => $diagnose->condition?->value
        );

        $newConditionValues = collect($diagnosesData)
            ->pluck('condition.identifier.value')
            ->filter()
            ->toArray();

        $existingDiagnoses->filter(
            fn (EncounterDiagnose $diagnose) => !in_array($diagnose->condition->value, $newConditionValues, true)
        )
            ->each(fn (EncounterDiagnose $diagnose) => $diagnose->delete());

        foreach ($diagnosesData as $diagnoseData) {
            $conditionValue = $diagnoseData['condition']['identifier']['value'];
            /** @var EncounterDiagnose|null $existingDiagnose */
            $existingDiagnose = $existingByConditionValue->get($conditionValue);

            if ($existingDiagnose) {
                $this->updateIdentifier($existingDiagnose->condition, $diagnoseData['condition']);
                $this->updateCodeableConcept($existingDiagnose->role, $diagnoseData['role']);
                $existingDiagnose->update(['rank' => $diagnoseData['rank'] ?? null]);
            } else {
                $condition = Repository::identifier()->store($conditionValue);
                Repository::codeableConcept()->attach($condition, $diagnoseData['condition']);

                $role = Repository::codeableConcept()->store($diagnoseData['role']);

                $encounter->diagnoses()->create([
                    'condition_id' => $condition->id,
                    'role_id' => $role->id,
                    'rank' => $diagnoseData['rank'] ?? null
                ]);
            }
        }
    }

    /**
     * Sync encounter hospitalization (HasOne) with nested codings and destination identifier.
     *
     * @param  Encounter  $encounter
     * @param  array|null  $hospitalization
     * @return void
     */
    protected function syncHospitalization(Encounter $encounter, ?array $hospitalization): void
    {
        if (empty($hospitalization)) {
            $encounter->hospitalization?->delete();

            return;
        }

        $existingHospitalization = $encounter->wasRecentlyCreated ? null : $encounter->hospitalization;

        $admitSource = $this->syncCoding(
            $existingHospitalization,
            $hospitalization['admit_source']['coding'][0] ?? null,
            'admitSource'
        );
        $reAdmission = $this->syncCoding(
            $existingHospitalization,
            $hospitalization['re_admission']['coding'][0] ?? null,
            'reAdmission'
        );
        $dischargeDisposition = $this->syncCoding(
            $existingHospitalization,
            $hospitalization['discharge_disposition']['coding'][0] ?? null,
            'dischargeDisposition'
        );
        $dischargeDepartment = $this->syncCoding(
            $existingHospitalization,
            $hospitalization['discharge_department']['coding'][0] ?? null,
            'dischargeDepartment'
        );
        $destination = $this->syncIdentifier($existingHospitalization, $hospitalization['destination'] ?? null, 'destination');

        $hospitalizationData = [
            'pre_admission_identifier' => $hospitalization['pre_admission_identifier'] ?? null,
            'admit_source_id' => $admitSource?->id,
            're_admission_id' => $reAdmission?->id,
            'destination_id' => $destination?->id,
            'discharge_disposition_id' => $dischargeDisposition?->id,
            'discharge_department_id' => $dischargeDepartment?->id
        ];

        if ($existingHospitalization) {
            $existingHospitalization->update($hospitalizationData);
        } else {
            $encounter->hospitalization()->create($hospitalizationData);
        }
    }

    /**
     * Get the condition UUID of the encounter's primary diagnosis, with the diagnoses loaded.
     *
     * @param  Encounter  $encounter
     * @return string|null
     */
    private function primaryConditionUuid(Encounter $encounter): ?string
    {
        return $encounter->diagnoses
            ->first(static fn (EncounterDiagnose $diagnosis): bool => $diagnosis->role->coding->first()?->code === 'primary')
            ?->condition
            ->value;
    }
}
