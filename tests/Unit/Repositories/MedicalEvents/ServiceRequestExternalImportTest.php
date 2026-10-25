<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories\MedicalEvents;

use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceRequestExternalImportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_single_import_preserves_zero_and_clinical_references_but_uses_resolved_author_and_patient(): void
    {
        [$person, $employee] = $this->personAndEmployee();
        $uuid = (string) Str::uuid();
        $activity = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        Repository::serviceRequest()->storeExternalIfMissing([
            'id' => $uuid, 'status' => 'completed', 'intent' => 'proposal', 'requisition' => 'SR-IMPORT',
            'code' => ['identifier' => ['value' => '59300-00']], 'quantity' => ['value' => 0],
            'category' => [['coding' => [['code' => 'procedure']]]], 'priority' => 'urgent',
            'basedOn' => [['identifier' => ['value' => $activity]]], 'context' => ['identifier' => ['value' => $encounter]],
            'occurrencePeriod' => ['start' => '2026-10-01T14:15:00+03:00'],
            'note' => [['text' => 'Imported note']], 'patientInstruction' => 'Imported instruction',
            'informWith' => 'phone', 'supportingInfo' => [['identifier' => ['value' => 'incomplete']]],
            'reasonReference' => [[]], 'employee_id' => 999999, 'person_id' => 999999,
        ], $employee, $person->id);

        $record = ServiceRequestRequest::where('uuid', $uuid)->firstOrFail();
        $this->assertSame($employee->id, $record->employeeId);
        $this->assertSame($person->id, $record->personId);
        $this->assertSame('active', $record->status);
        $this->assertSame('order', $record->intent->code);
        $this->assertSame(0.0, (float) $record->quantity);
        $this->assertSame($activity, $record->basedOn->value);
        $this->assertSame($encounter, $record->context->value);
        $this->assertSame('procedure', $record->category->text);
        $this->assertSame('urgent', $record->priority->text);
        $this->assertSame('SR-IMPORT', $record->requestNumber);
        $this->assertSame('2026-10-01', $record->startedAt->toDateString());
        $this->assertSame('Imported note', $record->note);
        $this->assertSame('Imported instruction', $record->patientInstruction);
        $this->assertSame('phone', $record->informWith);
        $this->assertSame([['uuid' => 'incomplete', 'type' => null]], $record->supportingInfo);
        $this->assertSame([['uuid' => null, 'type' => null]], $record->reasonReference);
    }

    public function test_single_import_skips_blank_uuid_and_never_updates_an_existing_record(): void
    {
        [$person, $employee] = $this->personAndEmployee();
        $repository = Repository::serviceRequest();
        $uuid = (string) Str::uuid();
        $repository->storeExternalIfMissing(['id' => $uuid, 'service' => ['id' => 'original']], $employee, $person->id);
        $record = $repository->findByUuid($uuid);
        $record->update(['status' => 'completed', 'note' => 'Keep local']);
        $original = $record->fresh()->getAttributes();
        $count = ServiceRequestRequest::count();

        $repository->storeExternalIfMissing(['uuid' => '', 'id' => (string) Str::uuid()], $employee, $person->id);
        $repository->storeExternalIfMissing(['id' => $uuid, 'service' => ['id' => 'replacement'], 'quantityInteger' => 9], $employee, $person->id);

        $this->assertSame($original, $record->fresh()->getAttributes());
        $this->assertSame($count, ServiceRequestRequest::count());
        $this->assertSame(1.0, (float) $record->fresh()->quantity);
    }

    public function test_batch_import_skips_existing_and_blank_rows_and_keeps_the_first_duplicate_author(): void
    {
        [$person, $employee] = $this->personAndEmployee();
        $otherAuthor = $employee->replicate();
        $otherAuthor->uuid = (string) Str::uuid();
        $otherAuthor->save();
        $repository = Repository::serviceRequest();
        $existingUuid = (string) Str::uuid();
        $newUuid = (string) Str::uuid();
        $repository->storeExternalIfMissing(['id' => $existingUuid, 'service' => ['id' => 'original']], $employee, $person->id);
        $existing = $repository->findByUuid($existingUuid)->getAttributes();
        $count = ServiceRequestRequest::count();

        $repository->storeExternalManyIfMissing([
            ['referral' => ['id' => $existingUuid, 'service' => ['id' => 'replacement']], 'employee' => $otherAuthor],
            ['referral' => [], 'employee' => $employee],
            ['referral' => ['id' => $newUuid, 'service' => ['id' => 'first'], 'quantityInteger' => 0], 'employee' => $employee],
            ['referral' => ['id' => $newUuid, 'service' => ['id' => 'duplicate'], 'quantityInteger' => 9], 'employee' => $otherAuthor],
        ], $person->id);

        $record = $repository->findByUuid($newUuid);
        $this->assertSame($count + 1, ServiceRequestRequest::count());
        $this->assertSame($existing, $repository->findByUuid($existingUuid)->getAttributes());
        $this->assertSame($employee->id, $record->employeeId);
        $this->assertSame($person->id, $record->personId);
        $this->assertSame('first', $record->serviceId);
        $this->assertSame(0.0, (float) $record->quantity);
        $this->assertSame('active', $record->status);
        $this->assertSame('order', $record->intent->code);
    }

    private function personAndEmployee(): array
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE',
            'patient_signed' => true, 'process_disclosure_data_consent' => true,
        ]);
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'status' => 'ACTIVE', 'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId, 'is_active' => true,
        ]);
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'employee_type' => 'DOCTOR', 'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id, 'is_active' => true, 'position' => 'Doctor',
            'start_date' => now()->toDateString(),
        ]);

        return [$person, $employee];
    }
}
