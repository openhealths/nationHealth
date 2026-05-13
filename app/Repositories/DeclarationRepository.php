<?php

declare(strict_types=1);

namespace App\Repositories;

use Exception;
use Carbon\Carbon;
use App\Enums\Status;
use App\Models\Division;
use App\Enums\JobStatus;
use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use App\Models\DeclarationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ReorganizationEmployeeDeclaration;

class DeclarationRepository
{
    /**
     * Store data during first request or local saving.
     *
     * @param  array  $validatedData
     * @return Declaration
     */
    public function store(array $validatedData): Declaration
    {
        $validatedData = $this->mapUuidsToIds($validatedData);

        return Declaration::create($validatedData);
    }

    /**
     * Map uuids to ids for setting relationship.
     *
     * @param  array  $data
     * @return array
     */
    private function mapUuidsToIds(array $data): array
    {
        $data['uuid'] = $data['id'];
        unset($data['id']);

        $data['declaration_request_id'] = DeclarationRequest::where('uuid', $data['declaration_request_id'])
            ->pluck('id')
            ->firstOrFail();
        $data['employee_id'] = Employee::withoutEagerLoads()
            ->where('uuid', $data['employee_id'])
            ->pluck('id')
            ->firstOrFail();
        $data['person_id'] = Person::where('uuid', $data['person_id'])->pluck('id')->firstOrFail();
        $data['division_id'] = Division::where('uuid', $data['division_id'])
            ->pluck('id')
            ->firstOrFail();
        $data['legal_entity_id'] = LegalEntity::where('uuid', $data['legal_entity_id'])
            ->pluck('id')
            ->firstOrFail();

        return $data;
    }

    public function storeMany(array $responseData, ?LegalEntity $legalEntity = null): void
    {
        $declarations = $responseData['declarations'];

        try {
            DB::transaction(function () use ($declarations, $legalEntity) {
                // Data for upserting persons
                $personsData = $this->mapPersonDataToUpsert($declarations);

                Person::upsert($personsData, uniqueBy: ['uuid']);

                $relationData = $this->mapRelationDataToUpsert($declarations);

                // Data for upserting declarations
                $declarationRequestsData = $this->mapDeclarationRequestDataToUpsert($declarations, $relationData, $legalEntity);

                DeclarationRequest::upsert($declarationRequestsData, uniqueBy: ['uuid']);

                // Data for upserting declaration requests
                $declarationsData = $this->mapDeclarationDataToUpsert($declarations, $relationData, $legalEntity);

                Declaration::upsert($declarationsData, uniqueBy: ['declaration_number']);

            });
        } catch (Exception $err) {
            Log::channel('db_errors')->error('Error storing declarations: ' . $err->getMessage());

            session()->flash('error', 'Виникла помилка при збереженні декларацій');
        }
    }

    protected function mapRelationDataToUpsert(array $declarations): array
    {
        $relationData = [];

        foreach ($declarations as $declaration) {
            $relationData['division'][$declaration['division']['uuid']] = Division::where('uuid', $declaration['division']['uuid'])->firstOrFail()?->id;

            $relationData['employee'][$declaration['employee']['uuid']] = Employee::where('uuid', $declaration['employee']['uuid'])->firstOrFail()?->id;

            $relationData['person'][$declaration['person']['uuid']] = Person::where('uuid', $declaration['person']['uuid'])->firstOrFail()?->id;
        }

        return $relationData;
    }

    public function mapPersonDataToUpsert(array $declarations): array
    {
        $personsData = [];

        foreach ($declarations as $declaration) {
            $personData = $declaration['person'];

            // If person with this uuid is already processed, skip to avoid duplicates
            if (isset($personData['uuid']) && \in_array($personData['uuid'], array_column($personsData, 'uuid'), true)) {
                continue;
            }

            $personData['birth_country'] ??= null;
            $personData['birth_settlement'] ??= null;
            $personData['gender'] ??= 'MALE';   // Here gender is defaulted to MALE if not provided
            $personData['no_tax_id'] ??= null;
            $personData['secret'] ??= null;
            $personData['emergency_contact'] ??= null;
            $personData['addresses'] ??= [];

            $personsData[] = array_intersect_key($personData, array_flip(new Person()->getFillable()));
        }

        return $personsData;
    }

    protected function mapDeclarationRequestDataToUpsert(array $declarations, array $relationData, ?LegalEntity $legalEntity = null): array
    {
        $legalEntity ??= legalEntity();

        $declarationRequestsData = [];

        foreach ($declarations as $declaration) {
            $data = [];

            $data['uuid'] = $declaration['declaration_request_uuid'];
            $data['legal_entity_id'] = $legalEntity->id;
            $data['division_id'] = $relationData['division'][$declaration['division']['uuid']];
            $data['employee_id'] = $relationData['employee'][$declaration['employee']['uuid']];
            $data['person_id'] = $relationData['person'][$declaration['person']['uuid']];
            $data['sync_status'] = JobStatus::PARTIAL->value;

            $declarationRequestsData[] = $data;
        }

        return $declarationRequestsData;
    }

    protected function mapDeclarationDataToUpsert(array $declarations, array $relationData, ?LegalEntity $legalEntity = null): array
    {
        $legalEntity ??= legalEntity();

        $declarationsData = [];

        foreach ($declarations as $declaration) {
            $data = [];

            $data['uuid'] = $declaration["uuid"];
            $data['declaration_number'] = $declaration['declaration_number'];
            $data['declaration_request_id'] = DeclarationRequest::where('uuid', $declaration['declaration_request_uuid'])->firstOrFail()?->id;
            $data['division_id'] = $relationData['division'][$declaration['division']['uuid']];
            $data['employee_id'] = $relationData['employee'][$declaration['employee']['uuid']];
            $data['legal_entity_id'] = $legalEntity->id;
            $data['person_id'] = $relationData['person'][$declaration['person']['uuid']];
            $data['end_date'] = $declaration['end_date'];
            $data['inserted_at'] = Carbon::parse($declaration['inserted_at'])->format('Y-m-d H:i:s');
            $data['signed_at'] = Carbon::createFromDate(1900, 1, 1)->format('Y-m-d');
            $data['reason'] = $declaration['reason'];
            $data['reason_description'] = $declaration['reason_description'];
            $data['start_date'] = $declaration['start_date'];
            $data['status'] = $declaration['status'];
            $data['sync_status'] = JobStatus::PARTIAL->value;

            $declarationsData[] = array_intersect_key($data, array_flip(new Declaration()->getFillable()));
        }

        return $declarationsData;
    }

    /**
     * Stores declaration data for a reorganized legal entity.
     *
     * Only processes records where the declarations has status 'active'.
     *
     * @param  array  $data  The payload containing 'employee' and 'party' sub-arrays with UUID and status fields.
     * @param  LegalEntity  $legalEntity
     *
     * @return void
     */
    public function storeDataForReorganizedLegalEntity(array $data, LegalEntity $legalEntity): void
    {
        if ($data['status'] !== strtolower(Status::ACTIVE->value)) {
            return;
        }

        $employee = Employee::where('uuid', $data['employee']['uuid'])->first();

        if ($employee->status !== Status::REORGANIZED || $employee->employee_type !== 'DOCTOR') {
            return;
        }

        $party = Party::where('id', $employee->party_id)->first();

        $declarationData = [
            'legal_entity_id' => $legalEntity->id,
            'legal_entity_uuid' => $data['legal_entity']['uuid'],
            'employee_id' => $employee?->id,
            'employee_uuid' => $employee?->uuid,
            'party_id' => $party?->id,
            'party_uuid' => $party?->uuid,
            'person_id' => Person::where('uuid', $data['person']['uuid'])->value('id') ?? null,
            'person_uuid' => $data['person']['uuid'],
            'declaration_id' => Declaration::where('uuid', $data['uuid'])->value('id') ?? null,
            'declaration_uuid' => $data['uuid'],
            'declaration_number' => $data['declaration_number'],
            'authorize_with' => DeclarationRequest::where('uuid', $data['declaration_request_uuid'])->value('authorize_with') ?? null,
            'updated_at'=> now(),
        ];

        ReorganizationEmployeeDeclaration::updateOrCreate(
            [
                'legal_entity_uuid' => $data['legal_entity']['uuid'],
                'employee_uuid' => $data['employee']['uuid'],
                'declaration_uuid' => $data['uuid'],
            ],
            $declarationData
        );
    }
}
