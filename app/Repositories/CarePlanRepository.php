<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Enums\CarePlanActivityKind;
use App\Enums\CarePlanCompletenessFilter;
use App\Enums\CarePlanStatus;
use App\Enums\CarePlanStatusFilter;
use App\Enums\EmployeeRole\Status as EmployeeRoleStatus;
use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Jobs\CarePlanInpatientApprovalProcessing;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Declaration;
use App\Models\EhealthLink;
use App\Models\EmployeeRole;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\MedicalEvents\BaseRepository as MedicalEventsBaseRepository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CarePlanRepository extends MedicalEventsBaseRepository
{
    public function __construct(CarePlan $model)
    {
        parent::__construct($model);
    }

    private const array FHIR_RELATIONS = ['subjectReference.type.coding', 'statusReasonConcept.coding', 'categoryConcept.coding', 'termsOfServiceConcept.coding', 'authorReference.type.coding', 'basedOn.type.coding', 'partOf.type.coding', 'encounterIdentifier.type.coding', 'careManager.type.coding', 'managingOrganization.type.coding', 'effectivePeriod', 'supportingInfoReferences.type.coding', 'contributors.type.coding'];

    private function uiRelations(): array
    {
        return [...self::FHIR_RELATIONS, 'person.names', 'author.party', 'encounter.episode', 'encounter.diagnoses.condition.type.coding', 'approvals.grantedTo.type.coding', ...array_map(static fn (string $relation): string => 'activities.'.$relation, CarePlanActivityRepository::FHIR_RELATIONS), 'activities.author.party'];
    }

    public function saveDraft(array $resource, int $personId, int $legalEntityId, ?int $localId = null): CarePlan
    {
        return $this->storageTransaction(function () use ($resource, $personId, $legalEntityId, $localId): CarePlan {
            if ($localId !== null) {
                $record = CarePlan::whereKey($localId)->lockForUpdate()->firstOrFail();
                if ($this->hasPendingSubmission($record)) {
                    throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
                }
            }

            return $this->storeResource($resource, $personId, $legalEntityId, $localId);
        });
    }

    public function addressConditions(CarePlan $plan): Collection
    {
        $uuids = collect($plan->addresses ?? [])->map(static function (array $address): ?string {
            $reference = $address['reference'] ?? null;

            return is_array($reference) ? data_get($reference, 'identifier.value') : (is_string($reference) ? basename($reference) : null);
        })->filter()->all();

        return Condition::wherePersonId($plan->personId)
            ->whereIn('uuid', $uuids)->with('code.coding')->get()->keyBy('uuid');
    }

    public function resolvePatientContext(?int $personId, ?string $patientUuid): array
    {
        $query = Person::query();
        $person = $personId === null ? $query->whereUuid($patientUuid)->firstOrFail() : $query->findOrFail($personId);
        if ($patientUuid !== null && $person->uuid !== $patientUuid) {
            throw new InvalidArgumentException('Patient identity does not match the synchronization context.');
        }

        return ['id' => $person->id, 'uuid' => $person->uuid];
    }

    /** Save list entries without treating a summary as a complete clinical resource. */
    public function storeSummaries(array $summaries, int $personId, int $legalEntityId): void
    {
        foreach ($summaries as $summary) {
            $summary = Arr::toCamelCase($summary);
            $plan = CarePlan::firstOrCreate([
                'person_id' => $personId,
                'legal_entity_id' => $legalEntityId,
                'uuid' => $summary['id'],
            ], [
                'title' => $summary['title'] ?? $summary['id'],
                'status' => $summary['status'],
                'requisition' => $summary['requisition'] ?? null,
            ]);
            if (!$plan->wasRecentlyCreated) {
                CarePlan::whereKey($plan->id)->update(['status' => $summary['status']]);
                CarePlan::whereKey($plan->id)->whereNull('details_synced_at')->update([
                    'title' => $summary['title'] ?? $plan->title,
                    'requisition' => $summary['requisition'] ?? $plan->requisition,
                ]);
            }
        }
    }

    public function storeAggregate(array $resource, array $activities, int $personId, int $legalEntityId): CarePlan
    {
        return $this->storageTransaction(function () use ($resource, $activities, $personId, $legalEntityId): CarePlan {
            $plan = $this->storeResource($resource, $personId, $legalEntityId);
            $activityRepository = Repository::carePlanActivity();
            foreach ($activities as $activityResource) {
                $activity = $activityRepository->storeResource($activityResource, $plan);
                $this->reconcileSubmissionFromResource($activity, $activityResource, 'detail.status');
            }
            $this->reconcileSubmissionFromResource($plan, $resource);

            return $plan->unsetRelations();
        });
    }

    public function assertFhirStorageReady(bool $beforeSubmission = false): void
    {
        $this->assertStorageSchema([
            'care_plans' => ['author_reference_id', 'terms_of_service_concept_id', 'subject_reference_id', 'status_reason_id', 'details_synced_at', 'based_on_id', 'part_of_id'],
            'care_plan_activities' => ['care_plan_reference_id', 'details_synced_at'],
            'care_plan_contributors' => ['care_plan_id', 'identifier_id', 'position'],
            'care_plan_activity_authors' => ['activity_id', 'identifier_id', 'position'],
            'care_plan_activity_reason_codes' => ['activity_id', 'concept_id', 'position'],
            'care_plan_activity_goal_concepts' => ['activity_id', 'concept_id', 'position'],
            'care_plan_activity_outcome_concepts' => ['activity_id', 'concept_id', 'position'],
        ], $beforeSubmission ? 'clinical-storage.submission_update_required' : 'clinical-storage.update_required');
    }

    /** Store a complete, normalized resource. This method performs no remote requests. */
    public function storeResource(array $resource, int $personId, int $legalEntityId, ?int $localId = null): CarePlan
    {
        if (!isset($resource['id'], $resource['title'], $resource['status'], $resource['period']['start'])) {
            throw new InvalidArgumentException('A complete Care Plan requires id, title, status and period.start.');
        }

        return $this->storageTransaction(function () use ($resource, $personId, $legalEntityId, $localId): CarePlan {
            $query = CarePlan::wherePersonId($personId)->whereLegalEntityId($legalEntityId)->lockForUpdate();
            $plan = $localId === null ? $query->whereUuid($resource['id'])->first() : $query->findOrFail($localId);
            if ($plan?->uuid !== null && $plan->uuid !== $resource['id']) {
                throw new InvalidArgumentException('Cannot replace an existing Care Plan identity.');
            }
            $plan ??= new CarePlan();
            $plan->personId = $personId;
            $plan->legalEntityId = $legalEntityId;
            $authorUuid = data_get($resource, 'author.identifier.value');
            $encounterUuid = data_get($resource, 'encounter.identifier.value');
            $attributes = [
                'uuid' => $resource['id'],
                'status' => $resource['status'],
                'title' => $resource['title'],
                'intent' => $resource['intent'] ?? null,
                'context' => $resource['context'] ?? null,
                'informWith' => data_get($resource, 'informWith.authMethodId'),
                'statusReasonId' => isset($resource['statusReason']) ? $this->syncCodeableConcept($plan->exists ? $plan : null, $resource['statusReason'], 'statusReasonConcept')->id : null,
                'description' => $resource['description'] ?? null,
                'note' => $resource['note'] ?? null,
                'addresses' => $resource['addresses'] ?? [],
                'requisition' => $resource['requisition'] ?? null,
                'authorId' => $authorUuid ? Employee::whereUuid($authorUuid)->whereLegalEntityId($legalEntityId)->value('id') : null,
                'encounterId' => $encounterUuid ? Encounter::whereUuid($encounterUuid)->value('id') : null,
                'detailsSyncedAt' => now(),
            ];
            foreach (['category' => ['categoryConcept', 'categoryId'], 'termsOfService' => ['termsOfServiceConcept', 'termsOfServiceConceptId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = isset($resource[$field]) ? $this->syncCodeableConcept($plan->exists ? $plan : null, $resource[$field], $relation)?->id : null;
            }
            foreach (['author' => ['authorReference', 'authorReferenceId'], 'basedOn' => ['basedOn', 'basedOnId'], 'partOf' => ['partOf', 'partOfId'], 'encounter' => ['encounterIdentifier', 'encounterIdentifierId'], 'careManager' => ['careManager', 'careManagerId'], 'managingOrganization' => ['managingOrganization', 'managingOrganizationId'], 'subject' => ['subjectReference', 'subjectReferenceId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = isset($resource[$field]) ? $this->syncIdentifier($plan->exists ? $plan : null, $resource[$field], $relation)?->id : null;
            }
            $plan->forceFill($attributes)->save();
            MedicalEventsRepository::period()->sync($plan, !empty($resource['period']) ? $resource['period'] + ['start' => null] : null, 'effectivePeriod');
            $this->syncPivot($plan, 'supportingInfoReferences', $this->syncIdentifiers($plan, $resource['supportingInfo'] ?? [], 'supportingInfoReferences'));
            $this->syncPivot($plan, 'contributors', $this->syncIdentifiers($plan, $resource['contributor'] ?? [], 'contributors'));

            return $plan->unsetRelations();
        });
    }

    public function getResource(int|CarePlan $id): array
    {
        $plan = $id instanceof CarePlan ? $id->loadMissing(self::FHIR_RELATIONS) : CarePlan::with(self::FHIR_RELATIONS)->findOrFail($id);
        if ($plan->detailsSyncedAt === null) {
            throw new InvalidArgumentException('Care Plan must pass the FHIR backfill before use.');
        }
        $resource = [
            'id' => $plan->uuid,
            'status' => $plan->status,
            'title' => $plan->title,
            'intent' => $plan->intent,
            'context' => $plan->context,
            'informWith' => $plan->informWith ? ['authMethodId' => $plan->informWith] : null,
            'statusReason' => MedicalEventsRepository::codeableConcept()->toResource($plan->statusReasonConcept),
            'description' => $plan->description,
            'note' => $plan->note,
            'addresses' => $plan->addresses ?? [],
            'requisition' => $plan->requisition,
            'period' => $plan->effectivePeriod ? MedicalEventsRepository::period()->toResource($plan->effectivePeriod) : null,
            'supportingInfo' => $plan->supportingInfoReferences->map(fn ($reference): array => MedicalEventsRepository::identifier()->toReference($reference))->all(),
            'contributor' => $plan->contributors->map(fn ($reference): array => MedicalEventsRepository::identifier()->toReference($reference))->all(),
        ];
        foreach (['category' => 'categoryConcept', 'termsOfService' => 'termsOfServiceConcept'] as $field => $relation) {
            $resource[$field] = MedicalEventsRepository::codeableConcept()->toResource($plan->{$relation});
        }
        foreach (['author' => 'authorReference', 'basedOn' => 'basedOn', 'partOf' => 'partOf', 'encounter' => 'encounterIdentifier', 'careManager' => 'careManager', 'managingOrganization' => 'managingOrganization', 'subject' => 'subjectReference'] as $field => $relation) {
            $resource[$field] = MedicalEventsRepository::identifier()->toReference($plan->{$relation});
        }

        return array_filter($resource, static fn (mixed $value): bool => $value !== null);
    }

    public function getByLegalEntity(int $legalEntityId): Collection
    {
        return CarePlan::whereLegalEntityId($legalEntityId)
            ->with($this->uiRelations())
            ->tap(fn ($query) => $this->orderForDisplay($query))
            ->get();
    }

    public function getByPersonId(int $personId, array $filters = [], ?int $legalEntityId = null): Collection
    {
        $query = CarePlan::wherePersonId($personId)
            ->with($this->uiRelations());
        if ($legalEntityId !== null) {
            $query->whereLegalEntityId($legalEntityId);
        }

        $this->filterCompleteness($query, $filters['completeness'] ?? CarePlanCompletenessFilter::ALL->value);

        if (!empty($filters['name'])) {
            $query->where('title', 'like', "%{$filters['name']}%");
        }

        $this->filterStatus($query, $filters['status'] ?? CarePlanStatusFilter::ALL->value);

        if (!empty($filters['start_date'])) {
            $query->whereHas('effectivePeriod', fn ($period) => $period->where('start', '>=', CarbonImmutable::parse($filters['start_date'], config('app.timezone'))->startOfDay()->utc()));
        }

        if (!empty($filters['end_date'])) {
            $query->whereHas('effectivePeriod', fn ($period) => $period->where('end', '<=', CarbonImmutable::parse($filters['end_date'], config('app.timezone'))->setTime(23, 59, 59)->utc()));
        }

        foreach (['based_on' => 'basedOn', 'part_of' => 'partOf', 'managing_organization_id' => 'managingOrganization'] as $field => $relation) {
            if (!empty($filters[$field])) {
                $query->whereHas($relation, fn ($reference) => $reference->where('value', $filters[$field]));
            }
        }
        if (!empty($filters['period_date'])) {
            $date = CarbonImmutable::parse($filters['period_date'], config('app.timezone'));
            $query->whereHas('effectivePeriod', fn ($period) => $period
                ->where('start', '<=', $date->endOfDay()->utc())
                ->where(fn ($end) => $end->whereNull('end')->orWhere('end', '>=', $date->startOfDay()->utc())));
        }
        if (!empty($filters['encounter_id']) && Str::isUuid($filters['encounter_id'])) {
            $query->whereHas('encounterIdentifier', fn ($reference) => $reference->where('value', $filters['encounter_id']));
        } elseif (!empty($filters['encounter_id'])) {
            $castType = $query->getConnection()->getDriverName() === 'mysql' ? 'CHAR' : 'TEXT';
            $query->whereRaw('CAST(encounter_id AS '.$castType.') LIKE ?', ['%'.$filters['encounter_id'].'%']);
        }

        return $this->orderForDisplay($query)->get();
    }

    public function findById(int $id): ?CarePlan
    {
        $plan = CarePlan::with($this->uiRelations())->find($id);

        return $plan;
    }

    public function submissionForDisplay(int $id): array
    {
        $record = new CarePlan();
        $record->id = $id;
        $submission = $this->submissionState($record);
        $link = $submission['id'] !== null ? EhealthLink::find($submission['id']) : null;

        return [
            'plan' => $this->findById($id),
            'submission' => $submission,
            'jobId' => filled($link?->href) ? basename($link->href) : null,
        ];
    }

    public function findByUuid(string $uuid, ?int $legalEntityId = null, ?int $personId = null): ?CarePlan
    {
        $query = CarePlan::with($this->uiRelations())->whereUuid($uuid);
        if ($legalEntityId !== null) {
            $query->whereLegalEntityId($legalEntityId);
        }
        if ($personId !== null) {
            $query->wherePersonId($personId);
        }
        $plan = $query->first();

        return $plan;
    }

    public function create(array $resource, int $personId, int $legalEntityId): CarePlan
    {
        return $this->saveDraft($resource, $personId, $legalEntityId);
    }

    public function update(CarePlan $carePlan, array $data): bool
    {
        $this->saveDraft($data, $carePlan->personId, $carePlan->legalEntityId, $carePlan->id);

        return true;
    }

    public function updateById(int $id, array $data): bool
    {
        $carePlan = CarePlan::find($id);
        if (!$carePlan) {
            return false;
        }

        return $this->update($carePlan, $data);
    }



    public function availableGrounds(int $personId): array
    {
        return [
            'conditions' => Condition::wherePersonId($personId)->with('code.coding')->get(),
            'reports' => DiagnosticReport::wherePersonId($personId)->with('code')->get(),
            'observations' => Observation::wherePersonId($personId)->with('code.coding')->get(),
        ];
    }

    public function deleteDraft(int $id): bool
    {
        return $this->storageTransaction(function () use ($id): bool {
            $plan = CarePlan::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($plan->status !== CarePlanStatus::DRAFT->value || $this->hasPendingSubmission($plan)) {
                return false;
            }
            $activities = $plan->activities()->lockForUpdate()->get();
            if ($activities->contains(fn (CarePlanActivity $activity): bool => $this->hasPendingSubmission($activity))) {
                return false;
            }
            $repository = Repository::carePlanActivity();
            foreach (CarePlanActivityKind::cases() as $kind) {
                if ($repository->linkedDocuments($activities->pluck('uuid')->all(), $kind->value)->isNotEmpty()) {
                    return false;
                }
            }

            return (bool) $plan->delete();
        });
    }

    public function findEncounter(int|string $key, ?int $personId = null): ?Encounter
    {
        $query = Encounter::with(['period', 'episode', 'diagnoses.condition']);
        $person = $personId !== null ? Person::find($personId) : null;
        if ($personId !== null) {
            if ($person === null) {
                return null;
            }
            $person->filterRecordsWithMerged($query);
        }

        $encounter = Str::isUuid((string) $key) ? $query->whereUuid($key)->first() : $query->find((int) $key);
        if ($encounter !== null && $person !== null && (int) $encounter->getAttribute('person_id') !== $person->id) {
            // Merging preserves the original owner. Resolve the same eligible, readable record as the selector.
            if (Auth::user() === null || Auth::user()->cannot('view', [$encounter, $person])
                || !$this->mergedEncounterIsEligible($encounter, $person)) {
                return null;
            }
        }

        return $encounter;
    }

    public function encounterTermsOfService(string $uuid, int $personId): string
    {
        $encounter = $this->findEncounter($uuid, $personId);
        if ($encounter === null) {
            return '';
        }
        $encounter->loadMissing('class');

        return $encounter->class?->code === 'INPATIENT' ? 'INPATIENT' : 'OUTPATIENT';
    }

    public function hasRehabilitationObservation(CarePlan $plan, array $references): bool
    {
        $episodeUuid = $this->episodeUuid($plan);
        if ($episodeUuid === null) {
            return false;
        }
        $uuids = collect($references)->map(static function (mixed $reference): ?string {
            if (is_string($reference)) {
                return str_starts_with($reference, 'Observation/') ? substr($reference, 12) : null;
            }
            if (!is_array($reference)) {
                return null;
            }
            $type = $reference['type'] ?? data_get($reference, 'identifier.type.coding.0.code');

            return strtolower((string) $type) === 'observation'
                ? ($reference['uuid'] ?? data_get($reference, 'identifier.value')) : null;
        })->filter(static fn (?string $uuid): bool => $uuid !== null && Str::isUuid($uuid))->unique()->all();
        if ($uuids === []) {
            return false;
        }
        $encounterUuids = Encounter::wherePersonId($plan->personId)
            ->whereHas('episode', static fn ($query) => $query->whereValue($episodeUuid))->pluck('uuid')->all();

        return Observation::wherePersonId($plan->personId)
            ->whereIn('uuid', $uuids)->whereStatus(ObservationStatus::VALID->value)
            ->whereHas('context', static fn ($query) => $query->whereIn('value', $encounterUuids))
            ->whereHas('categories.coding', static fn ($query) => $query->whereSystem('eHealth/ICF/observation_categories'))
            ->exists();
    }

    public function findPatient(int $id): ?Person
    {
        return Person::with(['names', 'declarations' => fn ($query) => $query->active()->latest()->take(1)])->find($id);
    }

    public function nextDisplayNumber(): string
    {
        return (string) ((CarePlan::max('id') ?? 0) + 1);
    }

    public function findEpisode(string $uuid): ?Episode
    {
        return Episode::with('period')->whereUuid($uuid)->first();
    }

    public function activeEpisodes(Person $person): Collection
    {
        return Episode::forPatient($person)->whereStatus('active')->with('period')->get();
    }

    public function findCondition(string $uuid, ?int $personId = null): ?Condition
    {
        $query = Condition::with('code.coding')->whereUuid($uuid);
        $person = $personId !== null ? Person::find($personId) : null;
        if ($personId !== null) {
            if ($person === null) {
                return null;
            }
            $person->filterRecordsWithMerged($query);
        }

        $condition = $query->first();
        if ($condition !== null && $person !== null && (int) $condition->personId !== $person->id
            && (Auth::user() === null || Auth::user()->cannot('view', [$condition, $person]))) {
            return null;
        }

        return $condition;
    }

    public function encounterChoices(int $personId, bool $forCreation = false): array
    {
        $person = Person::find($personId);
        if ($person === null) {
            return ['encounters' => collect(), 'episodeNames' => collect()];
        }

        $legalEntityUuid = legalEntity()?->uuid;
        // Include only confirmed MPI merges; medical records keep their original person/preperson owner.
        $eligibleEpisodes = $person->filterRecordsWithMerged(Episode::query())
            ->whereHas('managingOrganization', fn ($query) => $query->whereValue($legalEntityUuid))
            ->selectRaw(DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb' ? 'CAST(uuid AS CHAR)' : 'CAST(uuid AS TEXT)');
        $encounters = $person->filterRecordsWithMerged(Encounter::query())->whereStatus('finished')
            ->when($legalEntityUuid !== null, fn ($query) => $query->whereHas(
                'episode',
                fn ($episode) => $episode->whereIn('value', $eligibleEpisodes)
            ))
            ->with(['period', 'episode'])->orderByDesc('ehealth_inserted_at')
            ->get(['id', 'uuid', 'person_id', 'preperson_id', 'status', 'performer_id', 'episode_id', 'ehealth_inserted_at'])
            ->reject(fn (Encounter $encounter): bool => (int) $encounter->getAttribute('person_id') !== $person->id
                && (Auth::user() === null || Auth::user()->cannot('view', [$encounter, $person])
                    || !$this->mergedEncounterIsEligible($encounter, $person)))
            ->values();
        if ($forCreation) {
            $encounters = $encounters->filter(function (Encounter $encounter) use ($personId): bool {
                try {
                    $this->creationAuthorForEncounter($encounter->uuid, $personId);

                    return true;
                } catch (ValidationException) {
                    return false;
                }
            })->values();
        }
        $episodeUuids = $encounters->pluck('episode.value')
            ->filter(static fn ($uuid): bool => is_string($uuid) && Str::isUuid($uuid))
            ->unique()->values()->all();
        $episodeNames = $episodeUuids === [] ? collect() : $person->filterRecordsWithMerged(Episode::query())
            ->whereIn('uuid', $episodeUuids)->pluck('name', 'uuid');

        return compact('encounters', 'episodeNames');
    }

    private function mergedEncounterIsEligible(Encounter $encounter, Person $person): bool
    {
        return legalEntity()?->uuid !== null
            && $encounter->status === EncounterStatus::FINISHED
            && $person->filterRecordsWithMerged(Episode::query())
                ->whereUuid($encounter->episode?->value)
                ->whereStatus('active')
                ->whereHas('managingOrganization', static fn ($query) => $query->whereValue(legalEntity()->uuid))
                ->exists();
    }

    public function doctors(int $legalEntityId): Collection
    {
        return Employee::whereLegalEntityId($legalEntityId)->whereIn('employee_type', [Role::DOCTOR, Role::SPECIALIST, Role::MED_COORDINATOR])->whereStatus(Status::APPROVED)->whereIsActive(true)->with('party')->get()
            ->filter(fn ($employee): bool => $employee->party !== null);
    }

    /**
     * Exact diagnosis allowlists from the official Care Plan configuration, version 3 (2026-05-27).
     * https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19626885125
     *
     * @return list<string>
     */
    public function categoryCodesForEncounter(string $uuid, int $personId): array
    {
        $addresses = $this->encounterData($uuid, $personId)['addresses'];
        $rules = json_decode(file_get_contents(resource_path('care-plan-category-conditions.json')), true, 512, JSON_THROW_ON_ERROR);
        $categories = [];
        foreach ($addresses as $address) {
            foreach ($address['coding'] ?? [] as $coding) {
                foreach ($rules[$coding['system'] ?? ''] ?? [] as $category => $codes) {
                    if (in_array($coding['code'] ?? null, $codes, true)) {
                        $categories[] = $category;
                    }
                }
            }
        }

        return array_values(array_unique($categories));
    }

    public function assertCategoryForEncounter(string $category, string $encounterUuid, int $personId): void
    {
        $allowedCategories = $this->categoryCodesForEncounter($encounterUuid, $personId);
        if ($allowedCategories === []) {
            throw ValidationException::withMessages(['form.category' => __('care-plan.category_diagnosis_unavailable')]);
        }
        if (!in_array($category, $allowedCategories, true)) {
            throw ValidationException::withMessages(['form.category' => __('errors.ehealth.messages.care_plan_category_diagnosis_mismatch')]);
        }
    }

    public function assertDraftSubmissionAllowed(CarePlan $plan): void
    {
        $current = CarePlan::whereKey($plan->id)->firstOrFail();
        if ($this->hasPendingSubmission($current)) {
            throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
        }
        if ($current->status !== CarePlanStatus::DRAFT->value) {
            throw ValidationException::withMessages(['submission' => __('care-plan.cannot_edit_non_draft')]);
        }
    }

    public function assertDraftSigningReady(CarePlan $plan): void
    {
        $this->assertDraftSubmissionAllowed($plan);
        $resource = $this->getResource($plan->id);
        $author = $this->creationAuthorForEncounter((string) data_get($resource, 'encounter.identifier.value'), (int) $plan->personId);
        if (data_get($resource, 'author.identifier.value') !== $author->uuid) {
            throw ValidationException::withMessages(['form.encounter' => __('care-plan.encounter_author_required')]);
        }
        $this->assertRelatedPlans([
            'basedOn' => data_get($resource, 'basedOn.identifier.value'),
            'partOf' => data_get($resource, 'partOf.identifier.value'),
        ], (int) $plan->personId, $plan->id);
        $this->assertCategoryForEncounter(
            (string) data_get($resource, 'category.coding.0.code', ''),
            (string) data_get($resource, 'encounter.identifier.value', ''),
            (int) $plan->personId
        );
    }

    public function encounterData(string $uuid, int $personId, ?string $conditionUuid = null): array
    {
        $encounter = $this->findEncounter($uuid, $personId);
        $data = ['id' => $encounter?->id, 'addresses' => [], 'period_start' => $encounter?->period?->getRawOriginal('start')];
        $conditions = $encounter ? MedicalEventsRepository::encounter()->primaryDiagnosisConditions($encounter) : collect();
        foreach ($conditions as $condition) {
            // A merged preperson's diagnosis must retain the same original owner as its encounter.
            if ((int) $condition->prepersonId !== (int) $encounter->getAttribute('preperson_id')) {
                continue;
            }
            if ($conditionUuid && $conditionUuid !== $condition->uuid) {
                continue;
            }
            $concept = $condition?->code ? MedicalEventsRepository::codeableConcept()->toResource($condition->code) : null;
            if ($concept && !in_array($concept, $data['addresses'], true)) {
                $data['addresses'][] = $concept;
            }
        }

        return $data;
    }

    /** TV39 author is the current user's active employee that authored the base encounter. */
    public function creationAuthorForEncounter(string $uuid, int $personId): Employee
    {
        $encounter = $this->findEncounter($uuid, $personId);
        $authorUuid = $encounter?->performer?->value;
        $termsOfService = $this->encounterTermsOfService($uuid, $personId);
        $author = is_string($authorUuid) && $termsOfService !== ''
            ? Auth::user()?->getCarePlanWriterEmployee($termsOfService, $authorUuid)
            : null;
        if ($encounter === null || $encounter->status !== EncounterStatus::FINISHED || $author === null) {
            throw ValidationException::withMessages(['form.encounter' => __('care-plan.encounter_author_required')]);
        }

        return $author;
    }

    public function relatedPlanChoices(int $personId, ?int $exceptId = null): array
    {
        return CarePlan::query()->wherePersonId($personId)->whereLegalEntityId(legalEntity()?->id)
            ->whereNotIn('status', [CarePlanStatus::DRAFT->value, CarePlanStatus::PENDING->value])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereNotNull('uuid')->orderByDesc('id')->get(['id', 'uuid', 'title', 'requisition', 'legal_entity_id'])
            ->filter(fn (CarePlan $plan): bool => Auth::user() !== null && !Auth::user()->cannot('view', $plan))
            ->map(fn (CarePlan $plan): array => ['uuid' => $plan->uuid, 'label' => trim(($plan->requisition ?: $plan->uuid).' — '.$plan->title)])
            ->values()->all();
    }

    /** BA08: local, policy-filtered search shared by encounter document forms. No remote access is requested here. */
    public function documentPlanChoices(int $personId, string $encounterUuid, User $user, string $search = '', ?string $actingEmployeeUuid = null): array
    {
        $person = Person::find($personId);
        $encounter = $person === null ? null : $this->findEncounter($encounterUuid, $personId);
        if ($encounter === null || $user->cannot('view', [$encounter, $person])) {
            return [];
        }
        $termsOfService = $this->encounterTermsOfService($encounterUuid, $personId);
        // Existing plans may be used by another eligible employee with write approval.
        // A form's explicitly selected actor takes precedence over the encounter author.
        $employee = $user->getCarePlanWriterEmployee($termsOfService, $actingEmployeeUuid);
        if ($employee === null) {
            return [];
        }
        $search = trim($search);

        return CarePlan::wherePersonId($personId)->whereLegalEntityId(legalEntity()?->id)
            ->whereIn('status', [CarePlanStatus::PENDING->value, CarePlanStatus::ACTIVE->value])
            ->whereNotNull('uuid')->whereNotNull('details_synced_at')
            ->when($search !== '', static fn (Builder $query) => $query->where(static fn (Builder $matches) => $matches
                ->whereLike('title', '%'.$search.'%')->orWhereLike('requisition', '%'.$search.'%')->orWhereLike('uuid', '%'.$search.'%')))
            ->with($this->uiRelations())->orderByDesc('id')->get()
            ->filter(fn (CarePlan $plan): bool => !$user->cannot('manage', $plan) && !$user->cannot('view', $plan)
                && $plan->termsOfServiceCode() === $termsOfService && !$this->hasPendingSubmission($plan))
            ->map(fn (CarePlan $plan): array => [
                'id' => $plan->id, 'uuid' => $plan->uuid,
                'label' => trim(($plan->requisition ?: $plan->uuid).' — '.$plan->title),
                // The form must complete the existing approval flow before selecting/signing an activity.
                'approvalRequired' => $user->cannot('manageActivities', [$plan, $employee->uuid]),
            ])->values()->all();
    }

    public function documentActivityChoices(int $planId, int $personId, string $encounterUuid, User $user, string $kind, ?string $actingEmployeeUuid = null): array
    {
        $kind = CarePlanActivityKind::fromInput($kind)->value;
        if (!collect($this->documentPlanChoices($personId, $encounterUuid, $user, actingEmployeeUuid: $actingEmployeeUuid))->contains('id', $planId)) {
            return [];
        }
        $plan = $this->findById($planId);
        if ($user->cannot('manageActivities', [$plan, $actingEmployeeUuid])) {
            return [];
        }
        $repository = Repository::carePlanActivity();

        return CarePlanActivity::whereCarePlanId($planId)->whereKind($kind)
            ->whereIn('status', ['scheduled', 'in-progress'])->whereNotNull('details_synced_at')
            ->with(CarePlanActivityRepository::FHIR_RELATIONS)->get()
            ->filter(fn (CarePlanActivity $activity): bool => !$this->hasPendingSubmission($activity))
            ->map(function (CarePlanActivity $activity) use ($repository, $plan, $kind): array {
                $resource = $repository->getResource($activity);
                $detail = $resource['detail'];

                return [
                    'id' => $activity->id, 'uuid' => $activity->uuid, 'kind' => $kind,
                    'description' => $detail['description'] ?? null,
                    'productReference' => $detail['productReference'] ?? null,
                    'quantity' => $detail['quantity'] ?? null,
                    // Native remaining quantity and units are kept together; unknown is never treated as unlimited.
                    'remainingQuantity' => $detail['remainingQuantity'] ?? null,
                    'remainingQuantityType' => $detail['remainingQuantityType'] ?? null,
                    'scheduledPeriod' => $detail['scheduledPeriod'] ?? null,
                    'serviceCreationCapacity' => $kind === CarePlanActivityKind::SERVICE_REQUEST->value
                        ? $repository->serviceCreationCapacity(
                            $plan,
                            (string) data_get($detail, 'productReference.identifier.value', ''),
                            (string) data_get($detail, 'productReference.identifier.type.coding.0.code', 'service')
                        ) : null,
                ];
            })->values()->all();
    }

    public function assertRelatedPlans(array $form, int $personId, ?int $exceptId = null): void
    {
        $allowed = array_column($this->relatedPlanChoices($personId, $exceptId), 'uuid');
        foreach (['basedOn', 'partOf'] as $field) {
            $uuid = $form[$field] ?? null;
            if (filled($uuid) && !in_array($uuid, $allowed, true)) {
                throw ValidationException::withMessages(['form.'.$field => __('care-plan.related_plan_unavailable')]);
            }
        }
    }

    public function loadEncounter(Encounter $encounter): void
    {
        $encounter->loadMissing(['period', 'episode', 'diagnoses.condition']);
    }

    public function searchPatients(string $firstName, string $lastName, string $birthDate): Collection
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        return Person::query()
            ->where('birth_date', $birthDate)
            ->where(function ($query) use ($firstName, $lastName) {
                $query->whereHas('names', function ($nameQuery) use ($firstName, $lastName) {
                    $nameQuery->whereLike('first_name', $firstName)
                        ->whereLike('last_name', $lastName);
                })->orWhereHas('names', function ($nameQuery) use ($firstName, $lastName) {
                    $nameQuery->whereLike('first_name', $lastName)
                        ->whereLike('last_name', $firstName);
                });
            })
            ->with('names') // Eager load names so fullName works!
            ->get(['id', 'uuid', 'birth_date']);
    }

    /**
     * eHealth requires the care plan author (SPECIALIST/DOCTOR) to have an active employee_role
     * whose healthcare_service.providing_condition matches care_plan.terms_of_service.
     *
     * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19686719489/AR+Create+Care+Plan
     */
    public function authorHasActiveRoleForTermsOfService(Employee $author, string $termsOfService): bool
    {
        return EmployeeRole::query()
            ->whereEmployeeId($author->id)
            ->whereStatus(EmployeeRoleStatus::ACTIVE)
            ->whereIsActive(true)
            ->whereHas(
                'healthcareService',
                fn ($query) => $query
                    ->whereProvidingCondition($termsOfService)
                    ->whereIsActive(true)
            )
            ->exists();
    }

    public function patientsForSync(int $legalEntityId, User $user): Collection
    {
        $query = Declaration::active()->filterByLegalEntityId($legalEntityId);
        if (!$user->hasAllowedRole(Role::OWNER)) {
            $employeeIds = $user->party->employees()->whereLegalEntityId($legalEntityId)->pluck('id')->all();
            $query->forEmployees($employeeIds);
        }
        $ids = $query->pluck('person_id')->merge(CarePlan::whereLegalEntityId($legalEntityId)->pluck('person_id'))->unique()->all();

        return Person::whereIn('id', $ids)->get();
    }

    public function listing(int $legalEntityId, array $filters): Collection
    {
        $query = CarePlan::whereLegalEntityId($legalEntityId)->with($this->uiRelations());
        $this->filterCompleteness($query, $filters['completeness'] ?? CarePlanCompletenessFilter::ALL->value);
        $this->filterStatus($query, $filters['status'] ?? CarePlanStatusFilter::ALL->value);
        foreach (['title' => 'name', 'requisition' => 'requisition'] as $column => $filter) {
            if (!empty($filters[$filter])) {
                $query->whereLike($column, '%'.$filters[$filter].'%');
            }
        }
        if (!empty($filters['encounterId'])) {
            $castType = $query->getConnection()->getDriverName() === 'mysql' ? 'CHAR' : 'TEXT';
            $query->whereRaw('CAST(encounter_id AS '.$castType.') LIKE ?', ['%'.$filters['encounterId'].'%']);
        }
        foreach (['start' => 'startDate', 'end' => 'endDate'] as $boundary => $field) {
            if (!empty($filters[$field])) {
                $date = CarbonImmutable::parse($filters[$field], config('app.timezone'));
                $date = $boundary === 'start' ? $date->startOfDay() : $date->endOfDay();
                $query->whereHas('effectivePeriod', fn ($period) => $period->where($boundary, $boundary === 'start' ? '>=' : '<=', $date->utc()));
            }
        }

        return $this->orderForDisplay($query)->get();
    }

    private function filterStatus(Builder $query, string $status): void
    {
        if ($status === CarePlanStatusFilter::CURRENT->value) {
            $query->whereRaw('LOWER(status) IN (?, ?, ?, ?)', [
                CarePlanStatus::ACTIVE->value,
                CarePlanStatus::PENDING->value,
                CarePlanStatus::DRAFT->value,
                CarePlanStatus::ON_HOLD->value,
            ]);
        } elseif ($status !== '') {
            $query->whereRaw('LOWER(status) = ?', [CarePlanStatus::normalize($status)]);
        }
    }

    public function approvalQuery(int $personId, int $legalEntityId): Builder
    {
        return CarePlan::wherePersonId($personId)->whereLegalEntityId($legalEntityId)->whereNotNull('uuid');
    }

    public function incompleteApprovalUuids(int $personId, int $legalEntityId): array
    {
        $query = $this->approvalQuery($personId, $legalEntityId);
        $this->filterCompleteness($query, CarePlanCompletenessFilter::INCOMPLETE->value);

        return $query->pluck('uuid')->unique()->values()->all();
    }

    private function filterCompleteness(Builder $query, string $completeness): void
    {
        if ($completeness === CarePlanCompletenessFilter::COMPLETE->value) {
            $query->where(static fn (Builder $plans) => $plans
                ->whereRaw('LOWER(status) = ?', [CarePlanStatus::DRAFT->value])
                ->orWhere(static fn (Builder $complete) => $complete->whereNotNull('details_synced_at')
                    ->whereDoesntHave('activities', static fn (Builder $activities) => $activities->whereNull('details_synced_at'))));
        } elseif ($completeness === CarePlanCompletenessFilter::INCOMPLETE->value) {
            $query->whereRaw('LOWER(status) <> ?', [CarePlanStatus::DRAFT->value])
                ->where(static fn (Builder $plans) => $plans->whereNull('details_synced_at')
                    ->orWhereHas('activities', static fn (Builder $activities) => $activities->whereNull('details_synced_at')));
        }
    }

    private function orderForDisplay(Builder $query): Builder
    {
        return $query->orderByRaw("CASE
                WHEN LOWER(care_plans.status) = 'draft' THEN 0
                WHEN care_plans.details_synced_at IS NULL OR EXISTS (
                    SELECT 1 FROM care_plan_activities
                    WHERE care_plan_activities.care_plan_id = care_plans.id AND care_plan_activities.details_synced_at IS NULL
                ) THEN 1 ELSE 0 END")
            ->orderByRaw("CASE LOWER(care_plans.status) WHEN 'active' THEN 0 WHEN 'new' THEN 1 WHEN 'draft' THEN 2 WHEN 'on-hold' THEN 3
                WHEN 'cancelled' THEN 5 WHEN 'canceled' THEN 5 WHEN 'completed' THEN 5 WHEN 'terminated' THEN 5
                WHEN 'revoked' THEN 5 WHEN 'entered-in-error' THEN 5 ELSE 4 END")
            ->orderByRaw('COALESCE((SELECT start FROM periods WHERE periodable_id = care_plans.id AND periodable_type = ? LIMIT 1), care_plans.created_at) DESC', [(new CarePlan())->getMorphClass()])
            ->orderByDesc('care_plans.created_at')->orderByDesc('care_plans.id');
    }

    public function episodeUuid(CarePlan $plan): ?string
    {
        $plan->loadMissing('encounter.episode');

        return $plan->encounter?->episode?->value;
    }

    public function hasGrantedApproval(CarePlan $plan, ?string $employeeUuid): bool
    {
        if (!$employeeUuid) {
            return false;
        }
        $plan->loadMissing('approvals.grantedTo');

        // getAttribute('is_verified') avoids the camelCase collision with Approval::isVerified().
        return $plan->approvals->contains(fn ($approval): bool => $approval->grantedTo?->value === $employeeUuid
            && $approval->getAttribute('is_verified') && $approval->accessLevel === 'write'
            && $approval->expiresAt !== null && $approval->expiresAt->isFuture());
    }

    public function removeApproval(CarePlan $plan, string $uuid): void
    {
        $plan->approvals()->whereUuid($uuid)->delete();
    }

    public function approvalEmployees(int $legalEntityId): Collection
    {
        return Employee::whereLegalEntityId($legalEntityId)->whereStatus('APPROVED')->whereIsActive(true)->whereIn('employee_type', [Role::DOCTOR->value, Role::SPECIALIST->value, Role::MED_COORDINATOR->value])->with('party')->get();
    }

    public function assertApprovalCreationReady(CarePlan $plan): void
    {
        $plan = CarePlan::whereKey($plan->id)->firstOrFail();
        if ($this->hasPendingSubmission($plan)) {
            throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
        }
        if ($plan->status === CarePlanStatus::DRAFT->value || blank($plan->uuid)) {
            throw ValidationException::withMessages(['submission' => __('care-plan.cannot_grant_unregistered')]);
        }
    }

    public function assertApprovalRecipient(string $employeeUuid, int $legalEntityId): void
    {
        if (!Employee::whereUuid($employeeUuid)->whereLegalEntityId($legalEntityId)->whereStatus('APPROVED')->whereIsActive(true)->whereIn('employee_type', [Role::DOCTOR->value, Role::SPECIALIST->value, Role::MED_COORDINATOR->value])->exists()) {
            throw ValidationException::withMessages(['newApproval.employee_uuid' => __('care-plan.employee_not_found')]);
        }
    }

    public function hasPendingSubmission(CarePlan|CarePlanActivity $record): bool
    {
        return $record->ehealthLinks()->where('status', JobStatus::PENDING->value)->exists();
    }

    public function setInpatientApprovalProgress(EhealthLink $submission, string $status, ?int $linkId = null): void
    {
        $this->storageTransaction(function () use ($submission, $status, $linkId): void {
            $job = $submission->job()->lockForUpdate()->firstOrFail();
            $requestData = $job->request_data;
            $requestData['inpatientApproval'] = array_replace($requestData['inpatientApproval'] ?? [], ['status' => $status, 'linkId' => $linkId]);
            $job->update(['request_data' => $requestData]);
        });
    }

    public function inpatientApprovalProgress(CarePlan $plan): array
    {
        $submission = $this->latestSubmissionLink($plan);
        $progress = data_get($submission?->job?->request_data, 'inpatientApproval', []);
        if (($progress['status'] ?? null) !== 'queued') {
            return $progress;
        }
        $batchId = $progress['batchId'] ?? null;
        if ($batchId !== null) {
            $batch = Bus::findBatch($batchId);
            if ($batch === null || $batch->cancelled() || $batch->finished()) {
                $progress['status'] = 'failed';
            }
        } elseif ($submission->job->updated_at->lte(now()->subMinutes(2))) {
            // Older code could store "queued" before the job class loaded, without ever dispatching it.
            $progress['status'] = 'failed';
        }

        return $progress;
    }

    public function latestSubmissionLink(CarePlan $plan): ?EhealthLink
    {
        return $plan->ehealthLinks()->with('job')->latest('id')->first();
    }

    /** Persist the queue receipt and its batch together, so the page never waits for an untracked job. */
    public function queueInpatientApproval(EhealthLink $submission, LegalEntity $legalEntity, User $user, string $bearerToken, ?string $employeeUuid = null): void
    {
        $this->storageTransaction(function () use ($submission, $legalEntity, $user, $bearerToken, $employeeUuid): void {
            $job = $submission->job()->lockForUpdate()->firstOrFail();
            $requestData = $job->request_data;
            $progress = $requestData['inpatientApproval'] ?? [];
            if (in_array($progress['status'] ?? null, ['queued', 'pending'], true) && !empty($progress['batchId'])) {
                $existingBatch = Bus::findBatch($progress['batchId']);
                if ($existingBatch !== null && !$existingBatch->finished() && !$existingBatch->cancelled()) {
                    return;
                }
            }
            $batch = Bus::batch([
                (new CarePlanInpatientApprovalProcessing($submission, $legalEntity, $employeeUuid))->afterCommit(),
            ])->withOption('legal_entity_id', $legalEntity->id)
                ->withOption('token', Crypt::encryptString($bearerToken))
                ->withOption('user', $user)
                ->name(CarePlanInpatientApprovalProcessing::BATCH_NAME)->onQueue('sync')->dispatch();
            $requestData['inpatientApproval'] = ['status' => 'queued', 'linkId' => null, 'batchId' => $batch->id];
            $job->update(['request_data' => $requestData]);
        });
    }

    public function beginSubmission(CarePlan|CarePlanActivity $record, array $changes): EhealthLink
    {
        return $this->storageTransaction(function () use ($record, $changes) {
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($this->hasPendingSubmission($record)) {
                throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
            }

            $resource = $record instanceof CarePlan ? $this->getResource($record->id) : Repository::carePlanActivity()->getResource($record->id);

            return RemoteEHealthLinksProcessing::startSubmission($record, ['changes' => $changes, 'resource' => $resource]);
        });
    }

    /** Apply only a confirmed response; the request is never evidence that eHealth accepted it. */
    public function applySubmissionResult(EhealthLink $link, array $jobData): void
    {
        $record = $link->linkable;
        $data = $jobData['response_data'] ?? ($jobData['result'] ?? ($jobData['data'] ?? []));
        $data = $data[0] ?? $data;
        $data = $data['response_data'] ?? ($data['result'] ?? ($data['data'] ?? $data));
        $data = $data[0] ?? $data;
        $data = $data['data'] ?? $data;
        $data = Arr::toCamelCase($data);
        if (isset($data['id']) && $data['id'] !== $record->uuid) {
            throw ValidationException::withMessages(['id' => 'The processed resource does not match the submitted record.']);
        }
        if (isset($data['status']) && in_array(strtolower($data['status']), ['processed', 'pending', 'processing', 'success', 'failed', 'accepted', 'queued'], true)) {
            unset($data['status']);
            $data = array_diff_key($data, array_flip(['links', 'eta', 'jobId', 'statusCode']));
        }
        $changes = $link->job->request_data['changes'];
        $original = $link->job->request_data['resource'];
        if ($record instanceof CarePlan) {
            $resource = array_replace($original, $changes, $data);
            $this->storeResource($resource, $record->personId, $record->legalEntityId, $record->id);
        } else {
            $repository = Repository::carePlanActivity();
            $resource = $original;
            $resource['detail'] = array_replace($resource['detail'], $changes);
            foreach (['outcomeCodeableConcept', 'outcomeReference'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $resource[$field] = $changes[$field];
                    unset($resource['detail'][$field]);
                }
            }
            $detail = array_replace($resource['detail'], $data['detail'] ?? []);
            $resource = array_replace($resource, $data);
            $resource['detail'] = $detail;
            $repository->storeResource($resource, $record->carePlan, $record->id);
        }
    }

    public function finishSubmission(EhealthLink $link, array $jobData, bool $queueInpatientApproval = false): bool
    {
        return $this->storageTransaction(function () use ($link, $jobData, $queueInpatientApproval): bool {
            $link = EhealthLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->status !== JobStatus::PENDING->value) {
                return false;
            }
            $processed = strtolower((string) ($jobData['status'] ?? '')) === 'processed';
            if ($processed) {
                $this->applySubmissionResult($link, $jobData);
                if ($link->linkable instanceof CarePlanActivity && isset($jobData['carePlanResource'])) {
                    $plan = $link->linkable->carePlan;
                    $this->storeResource($jobData['carePlanResource'], $plan->personId, $plan->legalEntityId, $plan->id);
                }
            }
            $status = $processed ? JobStatus::PROCESSED->value : JobStatus::FAILED->value;
            if ($processed && $queueInpatientApproval && $link->linkable instanceof CarePlan) {
                $requestData = $link->job->request_data;
                $requestData['inpatientApproval'] = ['status' => 'queued', 'linkId' => null];
                $link->job()->update(['request_data' => $requestData]);
            }
            $link->job()->update(['status' => $status, 'response_data' => $jobData]);
            $link->update([
                'status' => $status,
                'error' => $processed ? null : (is_array($jobData['error'] ?? null) ? $jobData['error'] : ['message' => $jobData['error'] ?? __('errors.ehealth.messages.request_error')]),
                'error_code' => $processed ? null : ($jobData['status_code'] ?? null),
            ]);

            return true;
        });
    }

}
