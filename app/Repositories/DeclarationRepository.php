<?php

declare(strict_types=1);

namespace App\Repositories;

use Throwable;
use Carbon\Carbon;
use App\Enums\Status;
use App\Enums\Declaration\Status as DeclarationStatus;
use App\Models\Division;
use App\Models\ReorganizationEmployeeDeclaration;
use App\Enums\JobStatus;
use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use App\Models\DeclarationRequest;
use App\Classes\eHealth\Api\Responses\Collections\DeclarationGet;
use App\Classes\eHealth\Api\Responses\Collections\DeclarationGetMany;
use App\Dto\Declaration\Model as DeclarationData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use UnexpectedValueException;

class DeclarationRepository
{
    /**
     * Store a declaration returned after signing.
     *
     * @throws Throwable
     */
    public function store(DeclarationData $data): Declaration
    {
        return DB::transaction(function () use ($data): Declaration {
            $declaration = objectMapper()->map($data, Declaration::class);

            $this->setRelationshipIds($data, $declaration);

            $declaration->saveOrFail();

            return $declaration;
        });
    }

    /**
     * Sync full declaration data and related person details.
     *
     * @throws Throwable
     */
    public function syncDetails(DeclarationData $data, Declaration $declaration, ?LegalEntity $legalEntity): void {
        DB::transaction(function () use ($data, $declaration, $legalEntity): void {
            $this->syncPersonDetails($data, $declaration);

            objectMapper()->map($data, $declaration);

            $declaration->syncStatus = JobStatus::COMPLETED->value;

            $declaration->saveOrFail();
            $declaration->refresh();

            $this->terminateParentDeclaration($declaration);

            if ($legalEntity?->status === Status::REORGANIZED->value) {
                $this->storeDataForReorganizedLegalEntity($data, $legalEntity);
            }
        });
    }

    /**
     * Sync the person and confidant persons of a declaration.
     */
    private function syncPersonDetails(DeclarationData $data, Declaration $declaration): void {
        $person = $data->person;

        if ($person === null) {
            throw new UnexpectedValueException('Person details are missing for declaration: ' . $data->uuid);
        }

        $confidantPersons = $person['confidant_person'] ?? [];

        $allowedKeys = [
            ...new Person()->getFillable(),
            'first_name',
            'last_name',
            'second_name',
        ];

        $person = array_intersect_key($person, array_flip($allowedKeys));
        $person['id'] = $data->personUuid;
        $person['addresses'] ??= [];
        $person['documents'] ??= [];
        $person['phones'] ??= [];

        $person['authentication_methods'] = empty($data->authenticationMethodCurrent) ? [] : [$data->authenticationMethodCurrent];

        Repository::declarationRequest()->syncPersonData($person);

        if ($confidantPersons !== []) {
            $confidantPersons['person_id'] = $declaration->personId;

            Repository::confidantPerson()->addConfidantPerson($confidantPersons);
        }
    }

    /**
     * Terminate the parent declaration when its successor becomes active.
     */
    private function terminateParentDeclaration(Declaration $declaration): void {
        $parentDeclarationUuid = $declaration->declarationRequest?->parentDeclarationUuid;

        if ($declaration->status !== DeclarationStatus::ACTIVE || $parentDeclarationUuid === null) {
            return;
        }

        Declaration::whereUuid($parentDeclarationUuid)
            ->whereStatus(DeclarationStatus::ACTIVE)
            ->update([
                'status' => DeclarationStatus::TERMINATED,
                'is_active' => false,
            ]);
    }

    /**
     * Resolve eHealth relationship UUIDs to local database IDs.
     */
    private function setRelationshipIds(DeclarationData $data, Declaration $declaration): void {
        $declarationRequest = DeclarationRequest::whereUuid($data->declarationRequestUuid)->firstOrFail();

        $declaration->declarationRequestId = $declarationRequest->id;

        $declaration->employeeId = Employee::withoutEagerLoads()
            ->whereUuid($data->employeeUuid)
            ->pluck('id')
            ->firstOrFail();

        $declaration->personId = Person::whereUuid($data->personUuid)
            ->pluck('id')
            ->firstOrFail();

        $declaration->legalEntityId = LegalEntity::whereUuid($data->legalEntityUuid)->pluck('id')->firstOrFail();

        $declaration->divisionId = $data->divisionUuid === null ? $declarationRequest->divisionId : Division::whereUuid($data->divisionUuid)->value('id') ?? $declarationRequest->divisionId;
    }

    /**
     * Sync declarations and their related entities.
     *
     * @throws Throwable
     */
    public function sync(DeclarationGetMany $responses, LegalEntity $legalEntity): void {
        if ($responses->isEmpty()) {
            return;
        }

        $declarations = $responses->toBase()->map(
            static fn (DeclarationGet $response): DeclarationData => objectMapper()->map($response, DeclarationData::class)
        );

        DB::transaction(function () use ($declarations, $legalEntity): void {
            $this->syncPersons($declarations);

            $relationData = $this->resolveRelationshipIds($declarations);

            $this->syncDeclarationRequests($declarations, $relationData, $legalEntity);
            $this->syncDeclarations($declarations, $relationData, $legalEntity);
        });
    }

    /**
     * Resolve related entity UUIDs in bulk.
     *
     * @param Collection<int, DeclarationData> $declarations
     */
    private function resolveRelationshipIds(Collection $declarations): array {
        return [
            'division' => Division::whereIn('uuid',$declarations->pluck('divisionUuid')->filter()->unique())
                ->pluck('id', 'uuid'),

            'employee' => Employee::withoutEagerLoads()
                ->whereIn('uuid', $declarations->pluck('employeeUuid')->unique())
                ->pluck('id', 'uuid'),

            'person' => Person::whereIn('uuid', $declarations->pluck('personUuid')->unique())
                ->pluck('id', 'uuid'),
        ];
    }

    /**
     * Resolve a required relationship UUID to its local ID.
     */
    private function resolveLocalId(Collection $ids, string $uuid, string $field): int {
        $id = $ids->get($uuid);

        if ($id === null) {
            throw new UnexpectedValueException("Unknown {$field} UUID for declaration: {$uuid}");
        }

        return (int) $id;
    }

    /**
     * Sync persons and their name groups.
     *
     * @param Collection<int, DeclarationData> $declarations
     */
    private function syncPersons(Collection $declarations): void
    {
        $persons = $declarations
            ->unique('personUuid')
            ->keyBy('personUuid');

        $rows = $persons->map(
            static function (DeclarationData $data): array {
                $person = $data->person;

                return [
                    'uuid' => $data->personUuid,
                    'birth_date' => $person['birth_date'] ?? null,
                    'verification_status' => $person['verification_status'],
                    'birth_country' => $person['birth_country'] ?? null,
                    'birth_settlement' => $person['birth_settlement'] ?? null,
                    'gender' => $person['gender'] ?? 'MALE',
                    'no_tax_id' => $person['no_tax_id'] ?? null,
                    'secret' => $person['secret'] ?? null,
                    'emergency_contact' => $person['emergency_contact'] ?? null,
                ];
            }
        )->values()->all();

        Person::upsert($rows, uniqueBy: ['uuid'], update: ['birth_date', 'verification_status']);

        $personNames = $declarations->keyBy('personUuid');

        foreach (
            Person::with('names')
                ->whereIn('uuid', $personNames->keys())
                ->get() as $person
        ) {
            Repository::declarationRequest()->syncPersonNames($person, $personNames->get($person->uuid)->person);
        }
    }

    /**
     * Sync shortened declaration request data.
     *
     * @param Collection<int, DeclarationData> $declarations
     */
    private function syncDeclarationRequests(Collection $declarations, array $relationData, LegalEntity $legalEntity): void {
        $existingRequests = DeclarationRequest::whereIn('uuid', $declarations->pluck('declarationRequestUuid'))
            ->pluck('division_id', 'uuid');

        $existingDeclarations = Declaration::whereIn('uuid', $declarations->pluck('uuid'))
            ->pluck('division_id', 'uuid');

        $rows = $declarations->map(
            function (DeclarationData $data) use ($relationData, $legalEntity, $existingRequests, $existingDeclarations): array {
                return [
                    'uuid' => $data->declarationRequestUuid,
                    'legal_entity_id' => $legalEntity->id,
                    'division_id' => $relationData['division']->get($data->divisionUuid ?? '' ) ?? $existingRequests->get($data->declarationRequestUuid) ?? $existingDeclarations->get($data->uuid),
                    'employee_id' => $this->resolveLocalId($relationData['employee'], $data->employeeUuid, 'employee'),
                    'person_id' => $this->resolveLocalId($relationData['person'], $data->personUuid, 'person'),
                    'sync_status' => JobStatus::PARTIAL->value,
                ];
            }
        )->all();

        DeclarationRequest::upsert($rows, uniqueBy: ['uuid']);
    }

    /**
     * Sync shortened declarations using model DTOs.
     *
     * @param Collection<int, DeclarationData> $declarations
     */
    private function syncDeclarations(Collection $declarations, array $relationData, LegalEntity $legalEntity): void {
        $requests = DeclarationRequest::whereIn('uuid', $declarations->pluck('declarationRequestUuid'))->get([
            'id',
            'uuid',
            'division_id',
        ])->keyBy('uuid');

        $existingDeclarations = Declaration::whereIn('uuid', $declarations->pluck('uuid'))->get([
            'uuid',
            'division_id',
            'signed_at',
        ])->keyBy('uuid');

        $rows = $declarations->map(
            function (DeclarationData $data) use ($requests, $existingDeclarations, $relationData, $legalEntity): array {
                $request = $requests->get($data->declarationRequestUuid);

                if ($request === null) {
                    throw new UnexpectedValueException('Declaration request not found: ' . $data->declarationRequestUuid);
                }

                $existing = $existingDeclarations->get($data->uuid);
                $declaration = objectMapper()->map($data, Declaration::class);
                $declaration->declarationRequestId = $request->id;
                $declaration->legalEntityId = $legalEntity->id;
                $declaration->divisionId = $relationData['division']->get($data->divisionUuid ?? '') ?? $request->divisionId ?? $existing?->divisionId;
                $declaration->employeeId = $this->resolveLocalId($relationData['employee'], $data->employeeUuid, 'employee');
                $declaration->personId = $this->resolveLocalId($relationData['person'], $data->personUuid, 'person');
                $declaration->signedAt = $data->signedAt ?? $existing?->signedAt ?? Carbon::createFromDate(1900, 1, 1)->format('Y-m-d');
                $declaration->syncStatus = JobStatus::PARTIAL->value;

                return $declaration->getAttributes();
            }
        )->all();

        Declaration::upsert($rows, uniqueBy: ['declaration_number']);
    }

    /**
     * Store declaration data for a reorganized legal entity.
     */
    private function storeDataForReorganizedLegalEntity(DeclarationData $data, LegalEntity $legalEntity): void {
        if ($data->status !== DeclarationStatus::ACTIVE->value) {
            return;
        }

        $employee = Employee::whereUuid($data->employeeUuid)->first();

        if ($employee === null || $employee->status !== Status::REORGANIZED || $employee->employeeType !== 'DOCTOR') {
            return;
        }

        $party = Party::find($employee->partyId);

        $declarationRequest = DeclarationRequest::whereUuid($data->declarationRequestUuid)->first();

        $declarationData = [
            'legal_entity_id' => $legalEntity->id,
            'legal_entity_uuid' => $data->legalEntityUuid,

            'employee_id' => $employee->id,
            'employee_uuid' => $employee->uuid,

            'party_id' => $party?->id,
            'party_uuid' => $party?->uuid,

            'person_id' => Person::whereUuid($data->personUuid)->value('id'),
            'person_uuid' => $data->personUuid,

            'declaration_id' => Declaration::whereUuid($data->uuid)->value('id'),

            'declaration_uuid' => $data->uuid,
            'declaration_number' => $data->declarationNumber,

            'declaration_request_id' => $declarationRequest?->id,
            'declaration_request_uuid' => $declarationRequest?->uuid,
            'authorize_with' => $declarationRequest?->authorizeWith,
            'updated_at' => now(),
        ];

        ReorganizationEmployeeDeclaration::updateOrCreate(
            [
                'legal_entity_uuid' => $data->legalEntityUuid,
                'employee_uuid' => $data->employeeUuid,
                'declaration_uuid' => $data->uuid,
            ],
            $declarationData
        );
    }
}
