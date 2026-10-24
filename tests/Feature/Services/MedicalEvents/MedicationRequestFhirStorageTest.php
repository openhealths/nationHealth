<?php

declare(strict_types=1);

namespace Tests\Feature\Services\MedicalEvents;

use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class MedicationRequestFhirStorageTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(): array
    {
        $native = json_decode(file_get_contents(base_path('tests/Fixtures/medication-request-detail.json')), true, flags: JSON_THROW_ON_ERROR);
        $person = Person::create(['uuid' => $native['person']['id'], 'birth_date' => '1990-01-01', 'gender' => 'MALE', 'patient_signed' => true, 'process_disclosure_data_consent' => true]);

        return [$person, $native, app(MedicationRequestRepository::class)];
    }

    public function test_signed_acknowledgement_survives_an_invalid_detail_and_retry_is_idempotent(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $mrId = (string) Str::uuid();
        $repository->recordSigned($request, ['id' => $mrId, 'status' => 'ACTIVE']);
        $this->assertSame('signed', $request->status);
        $this->assertTrue($request->fhirMetadata['signedPrescriptionPending']);
        $this->assertSame($mrId, $request->ehealthPayload['active_id']);
        $this->assertArrayNotHasKey('signedPrescriptionPending', $repository->getResource($request));
        $wrong = array_replace($native, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        try {
            $repository->completeSignedPrescription($request, $wrong);
            $this->fail('A different MR must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame('signed', $request->fresh()->status);
            $this->assertTrue($request->fresh()->fhirMetadata['signedPrescriptionPending']);
        }
        $detail = array_replace($native, ['id' => $mrId, 'status' => 'ACTIVE']);
        $repository->completeSignedPrescription($request, $detail);
        $repository->completeSignedPrescription($request, $detail);
        $prescription = MedicationRequestRequest::whereUuid($mrId)->firstOrFail();
        $this->assertSame(1, $prescription->fhirVersion);
        $this->assertSame(MedicationRequestRequest::TYPE_PRESCRIPTION, $prescription->resourceType);
        $this->assertArrayNotHasKey('signedPrescriptionPending', $request->fhirMetadata);
        $this->assertSame(2, MedicationRequestRequest::wherePersonId($person->id)->count());
        $repository->saveStatus($prescription, 'rejected');
        $repository->completeSignedPrescription($request, $detail);
        $this->assertSame('rejected', $prescription->fresh()->status);
    }

    public function test_signed_import_failure_keeps_the_acknowledgement_and_patient_guard(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $mrId = (string) Str::uuid();
        $repository->recordSigned($request, ['id' => $mrId, 'status' => 'ACTIVE']);
        foreach (['patient', 'dosage'] as $failure) {
            $detail = array_replace($native, ['id' => $mrId, 'status' => 'ACTIVE']);
            if ($failure === 'patient') {
                $detail['person']['id'] = (string) Str::uuid();
            } else {
                unset($detail['dosage_instruction']);
            }
            try {
                $repository->completeSignedPrescription($request, $detail);
                $this->fail('Incomplete or foreign details must fail.');
            } catch (InvalidArgumentException) {
                $this->assertSame('signed', $request->fresh()->status);
                $this->assertTrue($request->fresh()->fhirMetadata['signedPrescriptionPending']);
                $this->assertDatabaseMissing('medication_request_requests', ['uuid' => $mrId]);
            }
        }
    }

    public function test_complete_detail_survives_normalized_storage_and_keeps_dosage_ids(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $resource = (new MedicationRequestMapper())->fromEHealth($native);
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $this->assertEquals($resource, $repository->getResource($request));
        $dosageId = $request->dosageInstructions()->value('id');
        $referenceIds = $request->fhirReferences()->pluck('identifiers.id')->all();
        $resource['note'] = 'Updated note';
        $repository->storeResource($resource, $person->id);
        $this->assertSame($dosageId, $request->dosageInstructions()->value('id'));
        $this->assertSame($referenceIds, $request->fhirReferences()->pluck('identifiers.id')->all());
        $this->assertEquals($resource, $repository->getResource($request->fresh()));
        $this->assertSame('0.1234567890', $request->fresh()->medicationQty);
    }

    public function test_storage_preserves_order_and_clears_removed_instructions_and_references(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $resource = (new MedicationRequestMapper())->fromEHealth($native);
        $resource['dosageInstruction'][] = array_replace($resource['dosageInstruction'][0], ['sequence' => 2, 'text' => 'second']);
        $request = $repository->storeResource($resource, $person->id);
        $this->assertCount(2, $repository->getResource($request)['dosageInstruction']);
        $resource['dosageInstruction'] = [];
        $resource['basedOn'] = [];
        $request = $repository->storeResource($resource, $person->id);
        $this->assertEquals($resource, $repository->getResource($request));
        $this->assertNull($request->fresh()->basedOnId);
    }

    public function test_partial_list_import_cannot_erase_dosages_or_change_resource_type(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $repository->upsertFromEHealth(['id' => $native['id'], 'status' => 'NEW'], $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $this->assertEquals((new MedicationRequestMapper())->fromEHealth($native), $repository->getResource($request->fresh()));
        $this->expectException(InvalidArgumentException::class);
        $repository->storeResource((new MedicationRequestMapper())->fromEHealth($native), $person->id, resourceType: MedicationRequestRequest::TYPE_PRESCRIPTION);
    }

    public function test_patient_mismatch_is_rejected_without_writing_any_clinical_data(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $native['person']['id'] = (string) Str::uuid();
        try {
            $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
            $this->fail('Expected patient mismatch.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, MedicationRequestRequest::whereUuid($native['id'])->count());
        }
    }

    public function test_same_uuid_cannot_be_reassigned_to_another_patient(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $other = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'FEMALE']);
        $native['person']['id'] = $other->uuid;
        $this->expectException(InvalidArgumentException::class);
        $repository->importDetails($native, $other->id, MedicationRequestRequest::TYPE_REQUEST);
    }

    public function test_unsupported_quantity_precision_is_rejected_before_storage(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $native['medication_info']['medication_qty'] = 0.12345678901;
        $this->expectException(InvalidArgumentException::class);
        $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
    }

    public function test_direct_profile_storage_rejects_json_numeric_precision_loss(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $resource = (new MedicationRequestMapper())->fromEHealth($native);
        $resource['quantity']['value'] = '99999999999999.1234567890';
        $this->expectException(InvalidArgumentException::class);
        $repository->storeResource($resource, $person->id);
    }

    public function test_preflight_is_read_only_and_legacy_records_need_detail_import(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $legacy = MedicationRequestRequest::create(['uuid' => $native['id'], 'person_id' => $person->id, 'status' => 'NEW']);
        DB::enableQueryLog();
        $report = $repository->preflight($person->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('importFullDetails', $report[0]['action']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|alter)/i', $query['query']);
        }
        $this->expectException(InvalidArgumentException::class);
        $repository->getResource($legacy);
    }

    public function test_signed_request_and_prescription_count_once_while_external_prescriptions_still_count(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $active = array_replace($native, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $repository->recordSigned($request, $active);
        $activityUuid = $native['based_on'][1]['identifier']['value'];
        $this->assertSame('signed', $request->fresh()->status);
        $this->assertEqualsWithDelta(0.123456789, $repository->occupiedQuantity($activityUuid), 0.0000000001);
        $external = array_replace($active, ['id' => (string) Str::uuid()]);
        $external['medication_info']['medication_qty'] = 0.25;
        $repository->importDetails($external, $person->id, MedicationRequestRequest::TYPE_PRESCRIPTION);
        $this->assertEqualsWithDelta(0.373456789, $repository->occupiedQuantity($activityUuid), 0.0000000001);
    }

    public function test_expired_linked_prescription_releases_its_signed_requests_reservation(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $active = array_replace($native, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $repository->recordSigned($request, $active);
        $activityUuid = $native['based_on'][1]['identifier']['value'];
        $this->assertEqualsWithDelta(0.123456789, $repository->occupiedQuantity($activityUuid), 0.0000000001);
        $active['status'] = 'EXPIRED';
        $repository->importDetails($active, $person->id, MedicationRequestRequest::TYPE_PRESCRIPTION);
        $this->assertSame('signed', $request->fresh()->status);
        $this->assertSame(0.0, $repository->occupiedQuantity($activityUuid));
    }
}
