<?php

declare(strict_types=1);

namespace Tests\Feature\Services\MedicalEvents;

use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class MedicationRequestFhirStorageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_documented_dispense_and_rejection_fields_are_rebuilt_from_columns(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $native['created_at'] = '2026-10-27T09:14:13Z';
        $native['dispense_valid_from'] = '2026-10-27';
        $native['dispense_valid_to'] = '2026-11-27';
        $native['rejected_at'] = '2026-10-27T10:14:13Z';
        $native['rejected_by'] = (string) Str::uuid();
        $native['reject_reason'] = 'Synthetic reason';
        $native['reject_reason_code'] = 'TEST';
        $record = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $resource = $repository->getResource($record);
        $this->assertSame($native['created_at'], $resource['createdAt']);
        $this->assertSame($native['dispense_valid_from'], $resource['dispenseValidFrom']);
        $this->assertSame($native['dispense_valid_to'], $resource['dispenseValidTo']);
        $this->assertSame($native['rejected_at'], $resource['rejectedAt']);
        $this->assertSame($native['rejected_by'], $resource['rejectedBy']);
        $this->assertDatabaseHas('medication_request_requests', ['id' => $record->id, 'reject_reason_code' => 'TEST']);
    }

    private function fixture(): array
    {
        $native = json_decode(file_get_contents(base_path('tests/Fixtures/medication-request-detail.json')), true, flags: JSON_THROW_ON_ERROR);
        $person = Person::create(['uuid' => $native['person']['id'], 'birth_date' => '1990-01-01', 'gender' => 'MALE', 'patient_signed' => true, 'process_disclosure_data_consent' => true]);

        return [$person, $native, Repository::medicationRequest()];
    }

    public function test_signed_acknowledgement_survives_an_invalid_detail_and_retry_is_idempotent(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $mrId = (string) Str::uuid();
        $repository->recordSigned($request, ['id' => $mrId, 'status' => 'ACTIVE']);
        $this->assertSame('signed', $request->status);
        $this->assertTrue($request->signedPrescriptionPending);
        $this->assertSame($mrId, $request->ehealthPayload['active_id']);
        $this->assertArrayNotHasKey('signedPrescriptionPending', $repository->getResource($request));
        $wrong = array_replace($native, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        try {
            $repository->completeSignedPrescription($request, $wrong);
            $this->fail('A different MR must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame('signed', $request->fresh()->status);
            $this->assertTrue($request->fresh()->signedPrescriptionPending);
        }
        $detail = array_replace($native, ['id' => $mrId, 'status' => 'ACTIVE']);
        $repository->completeSignedPrescription($request, $detail);
        $repository->completeSignedPrescription($request, $detail);
        $prescription = MedicationRequestRequest::whereUuid($mrId)->firstOrFail();
        $this->assertNotNull($prescription->detailsSyncedAt);
        $this->assertSame(MedicationRequestRequest::TYPE_PRESCRIPTION, $prescription->resourceType);
        $this->assertFalse($request->signedPrescriptionPending);
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
                $this->assertTrue($request->fresh()->signedPrescriptionPending);
                $this->assertDatabaseMissing('medication_request_requests', ['uuid' => $mrId]);
            }
        }
    }

    public function test_complete_detail_survives_normalized_storage_and_keeps_dosage_ids(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $resource = (new MedicationRequestMapper())->fromEHealth($native);
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $this->assertEquals($this->storedProfile($resource), $repository->getResource($request));
        $dosageId = $request->dosageInstructions()->value('id');
        $referenceIds = $request->references()->pluck('identifiers.id')->all();
        $resource['note'] = 'Updated note';
        $repository->storeResource($resource, $person->id);
        $this->assertSame($dosageId, $request->dosageInstructions()->value('id'));
        $this->assertSame($referenceIds, $request->references()->pluck('identifiers.id')->all());
        $this->assertEquals($this->storedProfile($resource), $repository->getResource($request->fresh()));
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
        $this->assertEquals($this->storedProfile($resource), $repository->getResource($request));
        $this->assertNull($request->fresh()->basedOnId);
    }

    public function test_partial_list_import_cannot_erase_dosages_or_change_resource_type(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $repository->upsertFromEHealth(['id' => $native['id'], 'status' => 'NEW'], $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $this->assertEquals($this->storedProfile((new MedicationRequestMapper())->fromEHealth($native)), $repository->getResource($request->fresh()));
        $this->expectException(InvalidArgumentException::class);
        $repository->storeResource((new MedicationRequestMapper())->fromEHealth($native), $person->id, resourceType: MedicationRequestRequest::TYPE_PRESCRIPTION);
    }

    public function test_complete_native_request_can_reclassify_an_unsynced_external_legacy_cache_once(): void
    {
        [$person, , $repository] = $this->fixture();
        $native = json_decode(file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')), true, flags: JSON_THROW_ON_ERROR)['request_detail'];
        $native['person']['id'] = $person->uuid;
        $legacy = $repository->upsertFromEHealth(['id' => $native['id'], 'status' => 'active'], $person->id);
        $this->assertNull($legacy->detailsSyncedAt);
        $this->assertSame(MedicationRequestRequest::TYPE_PRESCRIPTION, $legacy->resourceType);
        $request = $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $repository->importDetails($native, $person->id, MedicationRequestRequest::TYPE_REQUEST);
        $this->assertSame($legacy->id, $request->id);
        $this->assertSame(MedicationRequestRequest::TYPE_REQUEST, $request->resourceType);
        $this->assertNotNull($request->detailsSyncedAt);
        $this->assertEquals($native, $request->ehealthPayload);
        $this->assertSame(1, MedicationRequestRequest::whereUuid($native['id'])->count());
        $this->assertCount(count($native['dosage_instruction']), $repository->getResource($request)['dosageInstruction']);
    }

    public function test_legacy_reclassification_rejects_local_synced_foreign_reverse_or_malformed_details_without_writes(): void
    {
        [$person, , $repository] = $this->fixture();
        foreach (['local', 'synced', 'foreign', 'reverse', 'malformed', 'wrong-native-id'] as $case) {
            $native = json_decode(file_get_contents(base_path('tests/Fixtures/medication-official-responses.json')), true, flags: JSON_THROW_ON_ERROR)['request_detail'];
            $native['id'] = (string) Str::uuid();
            $native['person']['id'] = $person->uuid;
            $legacy = $repository->upsertFromEHealth(['id' => $native['id'], 'status' => 'active'], $person->id);
            if ($case === 'local') {
                $legacy->update(['source' => MedicationRequestRequest::SOURCE_LOCAL]);
            } elseif ($case === 'synced') {
                $legacy->update(['details_synced_at' => now()]);
            } elseif ($case === 'foreign') {
                $other = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'FEMALE']);
                $legacy->update(['person_id' => $other->id]);
            } elseif ($case === 'reverse') {
                $legacy->update(['resource_type' => MedicationRequestRequest::TYPE_REQUEST]);
            }
            $resource = (new MedicationRequestMapper())->fromEHealth($native);
            if ($case === 'malformed') {
                unset($native['employee']);
            } elseif ($case === 'wrong-native-id') {
                $native['id'] = (string) Str::uuid();
            }
            $before = $legacy->fresh()->getAttributes();
            $identifiers = DB::table('identifiers')->count();
            $dosages = DB::table('dosage_instructions')->count();
            try {
                $repository->storeResource($resource, $person->id, resourceType: $case === 'reverse'
                    ? MedicationRequestRequest::TYPE_PRESCRIPTION : MedicationRequestRequest::TYPE_REQUEST, native: $native);
                $this->fail('Rejected medication reclassification was accepted: '.$case);
            } catch (InvalidArgumentException|ValidationException) {
                $this->assertEquals($before, $legacy->fresh()->getAttributes(), $case);
                $this->assertSame($identifiers, DB::table('identifiers')->count(), $case);
                $this->assertSame($dosages, DB::table('dosage_instructions')->count(), $case);
            }
        }
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

    public function test_valid_quantity_round_trips_through_json_independently_of_php_string_precision(): void
    {
        [$person, $native, $repository] = $this->fixture();
        $resource = (new MedicationRequestMapper())->fromEHealth($native);
        foreach ([123456.123456789, '123456.123456789'] as $quantity) {
            $resource['quantity']['value'] = $quantity;
            $record = $repository->storeResource($resource, $person->id);
            $actual = $repository->getResource($record)['quantity']['value'];
            $this->assertSame('123456.123456789', json_encode($actual, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        }
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

    /** The local ESOZ profile deliberately stores Coding system/code only; native signing data stays complete. */
    private function storedProfile(array $resource): array
    {
        foreach ($resource as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $resource[$key] = $key === 'coding'
                ? array_map(static fn (array $coding): array => array_intersect_key($coding, array_flip(['system', 'code'])), $value)
                : $this->storedProfile($value);
        }

        return $resource;
    }

    public function test_period_preserves_date_only_and_full_instants_across_repeat_import_and_edit(): void
    {
        [$person, $native, $repository] = $this->fixture();
        foreach ([['start' => '2026-10-26', 'end' => '2026-11-02'], ['start' => '2026-10-26T02:03:04.123456+02:00', 'end' => '2026-11-02T23:59:59.654321Z']] as $period) {
            $resource = (new MedicationRequestMapper())->fromEHealth($native);
            $resource['period'] = $period;
            $record = $repository->storeResource($resource, $person->id);
            $repository->storeResource($resource, $person->id);
            $this->assertSame($period, $repository->getResource($record->fresh())['period']);
            $resource['note'] = 'Edited synthetic note';
            $repository->storeResource($resource, $person->id);
            $this->assertSame($period, $repository->getResource($record->fresh())['period']);
        }
    }
}
