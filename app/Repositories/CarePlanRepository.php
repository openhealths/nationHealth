<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\CarePlanStatus;
use App\Models\CarePlan;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Repositories\MedicalEvents\Concerns\PersistsCarePlanFhir;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use InvalidArgumentException;
use Illuminate\Support\Str;
use App\Services\MedicalEvents\Fhir;
use App\Core\Arr;
use Throwable;

class CarePlanRepository
{
    use PersistsCarePlanFhir;

    private const array FHIR_RELATIONS = ['categoryConcept.coding', 'termsOfServiceConcept.coding', 'authorReference.type.coding', 'encounterIdentifier.type.coding', 'careManager.type.coding', 'managingOrganization.type.coding', 'effectivePeriod', 'supportingInfoReferences.type.coding', 'contributors.type.coding'];

    private function uiRelations(): array
    {
        return [...self::FHIR_RELATIONS, 'person.names', 'author.party', 'encounter.episode', 'encounter.diagnoses.condition', 'approvals.grantedTo.type.coding', ...array_map(static fn (string $relation): string => 'activities.'.$relation, CarePlanActivityRepository::FHIR_RELATIONS), 'activities.author.party'];
    }

    public function formContext(array $form, ?int $localId = null): array
    {
        unset($form['keyContainerUpload'], $form['key_container_upload'], $form['password'], $form['knedp'], $form['keyContainerFileName']);
        $data = Arr::toCamelCase($form);
        foreach (['basedOn', 'partOf'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                unset($data[$field]);
            }
        }
        $resource = $localId === null ? [] : $this->getResource($localId);
        $authorUuid = $data['author'] ?? null;
        if (isset($data['authorId'])) {
            $authorUuid = Employee::whereKey($data['authorId'])->value('uuid');
        }
        $encounterUuid = $data['encounter'] ?? null;
        if (isset($data['encounterId'])) {
            $encounterUuid = Encounter::whereKey($data['encounterId'])->value('uuid');
        }
        $data['uuid'] = $data['uuid'] ?? $resource['id'] ?? Str::uuid()->toString();
        if ($authorUuid !== null) {
            $data['author'] = $authorUuid;
        }
        if ($encounterUuid !== null) {
            $data['encounter'] = $encounterUuid;
        }
        if (isset($data['supportingInfo']['episodes'])) {
            $data['episodes'] = $data['supportingInfo']['episodes'];
            $data['medicalRecords'] = $data['supportingInfo']['medicalRecords'] ?? [];
            unset($data['supportingInfo']);
        }
        foreach ($data['medicalRecords'] ?? [] as $index => $reference) {
            if (isset($reference['identifier'])) {
                continue;
            }
            $type = $reference['type'] ?? null;
            if (!in_array($type, ['procedure', 'diagnostic_report'], true) || !isset($reference['uuid'])) {
                throw new InvalidArgumentException('A medical record requires its resource type and UUID.');
            }
            $data['medicalRecords'][$index] = \App\Services\MedicalEvents\FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($reference['uuid']);
        }

        return ['data' => $data, 'context' => ['resource' => $resource, 'timezone' => config('app.timezone'), 'dateFormat' => config('app.date_format')]];
    }

    public function saveForm(array $form, int $personId, int $legalEntityId, ?int $localId = null): CarePlan
    {
        $input = $this->formContext($form, $localId);
        $resource = Fhir::carePlan()->toFhir($input['data'], $input['context']);

        return $this->present($this->storeResource($resource, $personId, $legalEntityId, $localId));
    }

    /** Compatibility projection for the existing UI; the historical columns are not written. */
    private function present(CarePlan $plan, bool $forListing = false): CarePlan
    {
        if ($plan->fhirVersion !== 1) {
            if ($forListing) {
                return $plan;
            }

            throw new InvalidArgumentException('Care Plan requires a full import or draft backfill before use.');
        }
        $resource = $this->getResource($plan);
        $form = Fhir::carePlan()->fromFhir($resource, ['timezone' => config('app.timezone')]);
        $plan->forceFill([
            'category' => $form['category'], 'termsOfService' => $form['termsOfService'],
            'context' => $resource['context'] ?? null,
            'periodStart' => data_get($resource, 'period.start'), 'periodEnd' => data_get($resource, 'period.end'),
            'informWith' => $form['informWith'] ?? null,
            'supportingInfo' => ['episodes' => array_map(static fn (array $episode): array => $episode + ['name' => $episode['uuid']], $form['episodes']), 'medical_records' => $form['medicalRecords']],
        ]);
        $plan->syncOriginal();
        if (!$forListing && $plan->relationLoaded('activities')) {
            $repository = app(CarePlanActivityRepository::class);
            $plan->setRelation('activities', $plan->activities->map(fn ($activity) => $repository->presentForUi($activity)));
        }

        return $plan;
    }

    public function resolvePatientContext(?int $personId, ?string $patientUuid): array
    {
        $query = \App\Models\Person\Person::query();
        $person = $personId === null ? $query->whereUuid($patientUuid)->firstOrFail() : $query->findOrFail($personId);
        if ($patientUuid !== null && $person->uuid !== $patientUuid) {
            throw new InvalidArgumentException('Patient identity does not match the synchronization context.');
        }

        return ['id' => $person->id, 'uuid' => $person->uuid];
    }

    public function storeAggregate(array $resource, array $activities, int $personId, int $legalEntityId): CarePlan
    {
        return DB::transaction(function () use ($resource, $activities, $personId, $legalEntityId): CarePlan {
            $plan = $this->storeResource($resource, $personId, $legalEntityId);
            app(CarePlanActivityRepository::class)->storeResources($activities, $plan);

            return $plan;
        });
    }

    /** Registered legacy resources require a full remote sync, not a guess from lossy columns. */
    public function backfillResources(int $legalEntityId, bool $write = false): array
    {
        $report = ['plans' => 0, 'activities' => 0, 'converted' => 0, 'needsRemoteSync' => [], 'errors' => []];
        CarePlan::whereLegalEntityId($legalEntityId)->with('activities')->chunkById(100, function ($plans) use (&$report): void {
            foreach ($plans as $plan) {
                $report['plans']++;
                $report['activities'] += $plan->activities->count();
                if (\App\Models\CarePlanActivity::whereCarePlanId($plan->id)->whereNotNull('uuid')->select('uuid')->groupBy('uuid')->havingRaw('COUNT(*) > 1')->exists()) {
                    $report['errors'][] = ['type' => 'duplicateActivity', 'localId' => $plan->id, 'reason' => 'Duplicate activity UUIDs must be reconciled before schema migration.'];
                }
                if ((int) ($plan->getAttributes()['fhir_version'] ?? 0) !== 1 && $plan->uuid !== null && $plan->status !== CarePlanStatus::DRAFT->value) {
                    $report['needsRemoteSync'][] = ['type' => 'carePlan', 'localId' => $plan->id];
                    continue;
                }
                try {
                    if ((int) ($plan->getAttributes()['fhir_version'] ?? 0) !== 1) {
                        $this->legacyDraftResource($plan);
                    }
                    foreach ($plan->activities as $activity) {
                        if ((int) ($activity->getAttributes()['fhir_version'] ?? 0) === 1) {
                            continue;
                        }
                        if ($activity->uuid !== null && $activity->status !== 'draft') {
                            $report['needsRemoteSync'][] = ['type' => 'activity', 'localId' => $activity->id];
                        } else {
                            app(CarePlanActivityRepository::class)->legacyDraftResource($activity, $plan);
                        }
                    }
                } catch (Throwable $exception) {
                    $report['errors'][] = ['type' => 'carePlan', 'localId' => $plan->id, 'reason' => $exception->getMessage()];
                }
            }
        });
        if (CarePlan::whereLegalEntityId($legalEntityId)->whereNotNull('uuid')->select('person_id', 'uuid')->groupBy('person_id', 'uuid')->havingRaw('COUNT(*) > 1')->exists()) {
            $report['errors'][] = ['type' => 'duplicateCarePlan', 'reason' => 'Duplicate resource UUIDs must be reconciled before schema migration.'];
        }
        if (!$write || $report['errors'] !== [] || $report['needsRemoteSync'] !== []) {
            return $report;
        }
        CarePlan::whereLegalEntityId($legalEntityId)->chunkById(100, function ($plans) use (&$report, $legalEntityId): void {
            foreach ($plans as $plan) {
                DB::transaction(function () use ($plan, $legalEntityId, &$report): void {
                    $plan = CarePlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                    if ($plan->fhirVersion !== 1) {
                        $plan = $this->storeResource($this->legacyDraftResource($plan), $plan->personId, $legalEntityId, $plan->id);
                        $report['converted']++;
                    }
                    $repository = app(CarePlanActivityRepository::class);
                    foreach ($plan->activities()->get() as $activity) {
                        if ($activity->fhirVersion !== 1) {
                            $repository->storeResource($repository->legacyDraftResource($activity, $plan), $plan, $activity->id);
                            $report['converted']++;
                        }
                    }
                });
            }
        });

        return $report;
    }

    private function legacyDraftResource(CarePlan $plan): array
    {
        $plan->loadMissing(['author', 'encounter', 'effectivePeriod', 'categoryConcept.coding', 'encounterIdentifier.type.coding', 'supportingInfoReferences.type.coding']);
        $data = Arr::toCamelCase($plan->getAttributes());
        $data['uuid'] = $plan->uuid ?? Str::uuid()->toString();
        $data['category'] = $plan->categoryConcept ? $this->fhirConcept($plan->categoryConcept) : $plan->category;
        $data['author'] = $plan->author?->uuid;
        if (!$data['author']) {
            throw new InvalidArgumentException('Draft author cannot be resolved.');
        }
        $data['addresses'] = $plan->addresses ?? [];
        $data['supportingInfo'] = $plan->supportingInfoReferences->map(fn ($reference): array => $this->fhirReference($reference))->all();
        if ($data['supportingInfo'] === [] && !empty($plan->supportingInfo)) {
            throw new InvalidArgumentException('Legacy supporting info requires explicit reconciliation.');
        }
        if ($plan->encounterIdentifier) {
            $data['encounter'] = $this->fhirReference($plan->encounterIdentifier);
        } elseif ($plan->encounter?->uuid) {
            $data['encounter'] = $plan->encounter->uuid;
        } elseif ($plan->encounterId !== null) {
            throw new InvalidArgumentException('Draft encounter cannot be resolved.');
        }
        if ($plan->effectivePeriod) {
            $data['period'] = MedicalEventsRepository::period()->toResource($plan->effectivePeriod);
        }

        return Fhir::carePlan()->toFhir($data, ['timezone' => config('app.timezone')]);
    }

    /** Store a complete, normalized resource. This method performs no remote requests. */
    public function storeResource(array $resource, int $personId, int $legalEntityId, ?int $localId = null): CarePlan
    {
        if (!isset($resource['id'], $resource['title'], $resource['status'], $resource['period']['start'])) {
            throw new InvalidArgumentException('A complete Care Plan requires id, title, status and period.start.');
        }

        return DB::transaction(function () use ($resource, $personId, $legalEntityId, $localId): CarePlan {
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
                'description' => $resource['description'] ?? null,
                'note' => $resource['note'] ?? null,
                'addresses' => $resource['addresses'] ?? [],
                'requisition' => $resource['requisition'] ?? null,
                'authorId' => $authorUuid ? Employee::whereUuid($authorUuid)->whereLegalEntityId($legalEntityId)->value('id') : null,
                'encounterId' => $encounterUuid ? Encounter::whereUuid($encounterUuid)->value('id') : null,
                'fhirVersion' => 1,
            ];
            foreach (['category' => ['categoryConcept', 'categoryId'], 'termsOfService' => ['termsOfServiceConcept', 'termsOfServiceConceptId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = $this->replaceFhirConcept($plan->exists ? $plan->{$relation} : null, $resource[$field] ?? null);
            }
            foreach (['author' => ['authorReference', 'authorReferenceId'], 'encounter' => ['encounterIdentifier', 'encounterIdentifierId'], 'careManager' => ['careManager', 'careManagerId'], 'managingOrganization' => ['managingOrganization', 'managingOrganizationId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = $this->replaceFhirReference($plan->exists ? $plan->{$relation} : null, $resource[$field] ?? null);
            }
            $known = ['id', 'status', 'title', 'intent', 'description', 'note', 'addresses', 'requisition', 'category', 'termsOfService', 'author', 'encounter', 'careManager', 'managingOrganization', 'period', 'supportingInfo', 'contributor'];
            $attributes['fhirMetadata'] = array_diff_key($resource, array_flip($known));
            $plan->forceFill($attributes)->save();
            MedicalEventsRepository::period()->syncExact($plan, $resource['period']);
            $this->syncFhirCollection($plan, 'supportingInfoReferences', $resource['supportingInfo'] ?? []);
            $this->syncFhirCollection($plan, 'contributors', $resource['contributor'] ?? [], positioned: true);

            return $plan;
        });
    }

    public function getResource(int|CarePlan $id): array
    {
        $plan = $id instanceof CarePlan ? $id->loadMissing(self::FHIR_RELATIONS) : CarePlan::with(self::FHIR_RELATIONS)->findOrFail($id);
        if ($plan->fhirVersion !== 1) {
            throw new InvalidArgumentException('Care Plan must pass the FHIR backfill before use.');
        }
        $resource = array_replace($plan->fhirMetadata ?? [], [
            'id' => $plan->uuid,
            'status' => $plan->status,
            'title' => $plan->title,
            'intent' => $plan->intent,
            'description' => $plan->description,
            'note' => $plan->note,
            'addresses' => $plan->addresses ?? [],
            'requisition' => $plan->requisition,
            'period' => $plan->effectivePeriod ? MedicalEventsRepository::period()->toResource($plan->effectivePeriod) : null,
            'supportingInfo' => $plan->supportingInfoReferences->map(fn ($reference): array => $this->fhirReference($reference))->all(),
            'contributor' => $plan->contributors->map(fn ($reference): array => $this->fhirReference($reference))->all(),
        ]);
        foreach (['category' => 'categoryConcept', 'termsOfService' => 'termsOfServiceConcept'] as $field => $relation) {
            $resource[$field] = $this->fhirConcept($plan->{$relation});
        }
        foreach (['author' => 'authorReference', 'encounter' => 'encounterIdentifier', 'careManager' => 'careManager', 'managingOrganization' => 'managingOrganization'] as $field => $relation) {
            $resource[$field] = $this->fhirReference($plan->{$relation});
        }

        return array_filter($resource, static fn (mixed $value): bool => $value !== null);
    }

    public function getByLegalEntity(int $legalEntityId): Collection
    {
        return CarePlan::whereLegalEntityId($legalEntityId)
            ->with($this->uiRelations())
            ->latest()
            ->get()->map(fn (CarePlan $plan): CarePlan => $this->present($plan, forListing: true));
    }

    public function getByPersonId(int $personId, array $filters = [], ?int $legalEntityId = null): Collection
    {
        $query = CarePlan::wherePersonId($personId)
            ->with($this->uiRelations());
        if ($legalEntityId !== null) {
            $query->whereLegalEntityId($legalEntityId);
        }

        if (!empty($filters['name'])) {
            $query->where('title', 'like', "%{$filters['name']}%");
        }

        if (!empty($filters['status'])) {
            $query->whereRaw('LOWER(status) = LOWER(?)', [$filters['status']]);
        }

        if (!empty($filters['start_date'])) {
            $query->whereHas('effectivePeriod', fn ($period) => $period->where('start', '>=', \Carbon\CarbonImmutable::parse($filters['start_date'], config('app.timezone'))->startOfDay()->utc()));
        }

        if (!empty($filters['end_date'])) {
            $query->whereHas('effectivePeriod', fn ($period) => $period->where('end', '<=', \Carbon\CarbonImmutable::parse($filters['end_date'], config('app.timezone'))->setTime(23, 59, 59)->utc()));
        }

        if (!empty($filters['encounter_id'])) {
            $query->whereRaw('CAST(encounter_id AS TEXT) LIKE ?', ['%'.$filters['encounter_id'].'%']);
        }

        return $query->latest()->get()->map(fn (CarePlan $plan): CarePlan => $this->present($plan, forListing: true));
    }

    public function findById(int $id): ?CarePlan
    {
        $plan = CarePlan::with($this->uiRelations())->find($id);

        return $plan ? $this->present($plan) : null;
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

        return $plan ? $this->present($plan) : null;
    }

    public function create(array $data): CarePlan
    {
        $input = Arr::toCamelCase($data);

        return $this->saveForm($input, (int) $input['personId'], (int) $input['legalEntityId']);
    }

    public function update(CarePlan $carePlan, array $data): bool
    {
        $this->saveForm($data, $carePlan->personId, $carePlan->legalEntityId, $carePlan->id);

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

    public function resolveEHealthPeriodBounds(CarePlan $carePlan): array
    {
        $period = $this->getResource($carePlan->id)['period'] ?? [];

        return ['start' => isset($period['start']) ? \Carbon\CarbonImmutable::parse($period['start'], 'UTC') : null, 'end' => isset($period['end']) ? \Carbon\CarbonImmutable::parse($period['end'], 'UTC') : null];
    }

    public function availableGrounds(int $personId): array
    {
        // Fetch patient conditions for outcomeReference selection
        $conditions = \App\Models\MedicalEvents\Sql\Condition::wherePersonId($personId)
            ->with('code.coding')->get()->map(fn ($c) => [
                'uuid' => $c->uuid,
                'name' => ($c->code?->text ?: null) ?? ($c->code?->coding?->first()?->code ?: null) ?? 'Unknown Condition',
                'date' => $c->onsetDate ? \Carbon\Carbon::parse($c->onsetDate)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient diagnostic reports for justifications (grounds)
        $reports = \App\Models\MedicalEvents\Sql\DiagnosticReport::wherePersonId($personId)
            ->with('code')
            ->get()->map(fn ($dr) => [
                'uuid' => $dr->uuid,
                'name' => $dr->code?->displayValue ?: $dr->code?->value ?: 'Diagnostic Report',
                'date' => $dr->issued ? \Carbon\Carbon::parse($dr->issued)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient observations for justifications (grounds)
        $observations = \App\Models\MedicalEvents\Sql\Observation::wherePersonId($personId)
            ->with('code.coding')->get()->map(fn ($obs) => [
                'uuid' => $obs->uuid,
                'name' => $obs->code?->text ?: 'Observation',
                'date' => $obs->issued ? \Carbon\Carbon::parse($obs->issued)->format('d.m.Y') : '-',
            ])->toArray();

        return compact('conditions', 'reports', 'observations');
    }

    public function deleteDraft(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $plan = CarePlan::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($plan->status !== CarePlanStatus::DRAFT->value) {
                return false;
            }
            $activities = $plan->activities()->lockForUpdate()->get();
            $repository = app(CarePlanActivityRepository::class);
            foreach (\App\Enums\CarePlanActivityKind::cases() as $kind) {
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
        if ($personId !== null) {
            $query->wherePersonId($personId);
        }

        return Str::isUuid((string) $key) ? $query->whereUuid($key)->first() : $query->find((int) $key);
    }

    public function findPatient(int $id): ?\App\Models\Person\Person
    {
        return \App\Models\Person\Person::with(['names', 'declarations' => fn ($query) => $query->active()->latest()->take(1)])->find($id);
    }

    public function nextDisplayNumber(): string
    {
        return (string) ((CarePlan::max('id') ?? 0) + 1);
    }

    public function findEpisode(string $uuid): ?\App\Models\MedicalEvents\Sql\Episode
    {
        return \App\Models\MedicalEvents\Sql\Episode::with('period')->whereUuid($uuid)->first();
    }

    public function activeEpisodes(\App\Models\Person\Person $person): array
    {
        return \App\Models\MedicalEvents\Sql\Episode::forPatient($person)->whereStatus('active')->with('period')->get()
            ->map(fn ($episode): array => ['uuid' => $episode->uuid, 'name' => $episode->name, 'date' => $episode->period?->start ? \Carbon\Carbon::parse($episode->period->start)->format('d.m.Y') : ''])->all();
    }

    public function findCondition(string $uuid, ?int $personId = null): ?\App\Models\MedicalEvents\Sql\Condition
    {
        $query = \App\Models\MedicalEvents\Sql\Condition::with('code.coding')->whereUuid($uuid);
        if ($personId !== null) {
            $query->wherePersonId($personId);
        }

        return $query->first();
    }

    public function encounterChoices(int $personId): array
    {
        $encounters = Encounter::wherePersonId($personId)->whereStatus('finished')
            ->with(['period', 'episode'])->orderByDesc('ehealth_inserted_at')
            ->get(['id', 'uuid', 'episode_id', 'ehealth_inserted_at']);
        $episodeUuids = $encounters->pluck('episode.value')
            ->filter(static fn ($uuid): bool => is_string($uuid) && Str::isUuid($uuid))
            ->unique()->values()->all();
        $episodeNames = $episodeUuids === [] ? collect() : \App\Models\MedicalEvents\Sql\Episode::wherePersonId($personId)
            ->whereIn('uuid', $episodeUuids)->pluck('name', 'uuid');

        return $encounters->map(function (Encounter $encounter) use ($episodeNames): array {
            $occurredAt = $encounter->period === null
                ? $encounter->getRawOriginal('ehealth_inserted_at')
                : $encounter->period->getRawOriginal('start');
            $date = $occurredAt
                ? \Carbon\Carbon::parse($occurredAt, 'UTC')->setTimezone(config('app.timezone'))->format('d.m.Y')
                : '-';
            $episodeName = $episodeNames->get($encounter->episode?->value);
            $label = __('Взаємодія #').$encounter->id.' ('.$date.')';
            if (filled($episodeName)) {
                $label .= ' — '.$episodeName;
            }

            return ['uuid' => $encounter->uuid, 'label' => $label];
        })->all();
    }

    public function doctors(int $legalEntityId): array
    {
        return Employee::whereLegalEntityId($legalEntityId)->whereIn('employee_type', [\App\Enums\User\Role::DOCTOR, \App\Enums\User\Role::SPECIALIST])->whereStatus(\App\Enums\Status::APPROVED)->whereIsActive(true)->with('party')->get()
            ->filter(fn ($employee): bool => $employee->party !== null)->map(fn ($employee): array => ['uuid' => $employee->uuid, 'name' => ($employee->party->fullName ?? 'Unknown').' ('.($employee->position ?? '').')'])->values()->all();
    }

    public function encounterData(string $uuid, int $personId, ?string $conditionUuid = null): array
    {
        $encounter = $this->findEncounter($uuid, $personId);
        $data = ['id' => $encounter?->id, 'addresses' => [], 'period_start' => $encounter?->period?->getRawOriginal('start')];
        $conditions = $encounter ? MedicalEventsRepository::encounter()->primaryDiagnosisConditions($encounter) : collect();
        foreach ($conditions as $condition) {
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

    public function loadEncounter(Encounter $encounter): void
    {
        $encounter->loadMissing(['period', 'episode', 'diagnoses.condition']);
    }

    public function searchPatients(string $firstName, string $lastName, string $birthDate): array
    {
        return \App\Models\Person\Person::query()
            ->where('birth_date', $birthDate)
            ->where(function ($query) use ($firstName, $lastName) {
                $query->whereHas('names', function ($nameQuery) use ($firstName, $lastName) {
                    $nameQuery->where('first_name', $firstName)
                        ->where('last_name', $lastName);
                })->orWhereHas('names', function ($nameQuery) use ($firstName, $lastName) {
                    $nameQuery->where('first_name', $lastName)
                        ->where('last_name', $firstName);
                });
            })
            ->with('names') // Eager load names so fullName works!
            ->get(['id', 'uuid', 'birth_date'])
            ->map(fn (\App\Models\Person\Person $person) => [
                'id' => $person->id,
                'uuid' => $person->uuid,
                'name' => trim(($person->primary_name?->last_name . ' ' . $person->primary_name?->first_name . ' ' . $person->primary_name?->second_name) ?? ''),
                'birthDate' => $person->birth_date
                    ? \Carbon\Carbon::parse($person->birth_date)->format(config('app.date_format'))
                    : '-',
            ])
            ->values()
            ->toArray();
    }

    /**
     * eHealth requires the care plan author (SPECIALIST/DOCTOR) to have an active employee_role
     * whose healthcare_service.providing_condition matches care_plan.terms_of_service.
     *
     * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19686719489/AR+Create+Care+Plan
     */
    public function authorHasActiveRoleForTermsOfService(Employee $author, string $termsOfService): bool
    {
        return \App\Models\EmployeeRole::query()
            ->whereEmployeeId($author->id)
            ->whereStatus(\App\Enums\EmployeeRole\Status::ACTIVE)
            ->whereIsActive(true)
            ->whereHas(
                'healthcareService',
                fn ($query) => $query
                    ->whereProvidingCondition($termsOfService)
                    ->whereIsActive(true)
            )
            ->exists();
    }

    public function patientsForSync(int $legalEntityId, \App\Models\User $user): Collection
    {
        $query = \App\Models\Declaration::active()->filterByLegalEntityId($legalEntityId);
        if (!$user->hasAllowedRole(\App\Enums\User\Role::OWNER)) {
            $employeeIds = $user->party->employees()->whereLegalEntityId($legalEntityId)->pluck('id')->all();
            $query->forEmployees($employeeIds);
        }
        $ids = $query->pluck('person_id')->merge(CarePlan::whereLegalEntityId($legalEntityId)->pluck('person_id'))->unique()->all();

        return \App\Models\Person\Person::whereIn('id', $ids)->get();
    }

    public function listing(int $legalEntityId, array $filters): Collection
    {
        $query = CarePlan::whereLegalEntityId($legalEntityId)->with($this->uiRelations());
        foreach (['status', 'title' => 'name', 'requisition'] as $column => $filter) {
            if (is_int($column)) {
                $column = $filter;
            }
            if (!empty($filters[$filter])) {
                $query->where($column, 'ilike', '%'.$filters[$filter].'%');
            }
        }
        if (!empty($filters['encounterId'])) {
            $query->whereRaw('CAST(encounter_id AS TEXT) LIKE ?', ['%'.$filters['encounterId'].'%']);
        }
        foreach (['start' => 'startDate', 'end' => 'endDate'] as $boundary => $field) {
            if (!empty($filters[$field])) {
                $date = \Carbon\CarbonImmutable::parse($filters[$field], config('app.timezone'));
                $date = $boundary === 'start' ? $date->startOfDay() : $date->endOfDay();
                $query->whereHas('effectivePeriod', fn ($period) => $period->where($boundary, $boundary === 'start' ? '>=' : '<=', $date->utc()));
            }
        }

        return $query->latest()->get()->map(fn (CarePlan $plan): CarePlan => $this->present($plan, forListing: true));
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

        return $plan->approvals->contains(fn ($approval): bool => $approval->grantedTo?->value === $employeeUuid && \App\Enums\Person\ApprovalStatus::resolve($approval->status)?->isGranted() === true);
    }

    public function removeApproval(CarePlan $plan, string $uuid): void
    {
        $plan->approvals()->whereUuid($uuid)->delete();
    }

    public function approvalEmployees(int $legalEntityId): array
    {
        return Employee::whereLegalEntityId($legalEntityId)->whereStatus('APPROVED')->whereIsActive(true)->whereIn('employee_type', [\App\Enums\User\Role::DOCTOR->value, \App\Enums\User\Role::SPECIALIST->value])->with('party')->get()
            ->map(fn ($employee): array => ['uuid' => $employee->uuid, 'label' => trim($employee->fullName).' ('.$employee->employeeType.')'])->all();
    }
}
