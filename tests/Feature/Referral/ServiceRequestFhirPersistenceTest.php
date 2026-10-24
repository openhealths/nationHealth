<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\Person\Person;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ServiceRequestFhirPersistenceTest extends TestCase
{
    use DatabaseTransactions;

    private function person(): Person
    {
        return Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE', 'patient_signed' => true, 'process_disclosure_data_consent' => true]);
    }

    private function resource(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_complete_resource_round_trip_and_repeat_import_are_lossless(): void
    {
        $person = $this->person();
        $repo = app(ServiceRequestRequestRepository::class);
        $resource = $this->resource();
        $record = $repo->storeResource($resource, $person->id);
        $counts = [DB::table('identifiers')->count(), DB::table('codings')->count(), DB::table('quantities')->count()];
        $this->assertEquals($resource, $repo->getResource($record));
        $repo->storeResource($resource, $person->id);
        $this->assertSame($counts, [DB::table('identifiers')->count(), DB::table('codings')->count(), DB::table('quantities')->count()]);
        $actual = $repo->getResource($record->id);
        $this->assertSame($resource['occurrencePeriod'], $actual['occurrencePeriod']);
        $this->assertSame($resource['basedOn'], $actual['basedOn']);
        $this->assertSame(1.23456789, $actual['quantity']['value']);
        $this->assertSame(0.0, $actual['remainingQuantity']['value']);
        $this->assertNull($record->employeeId, 'An unknown external author must not become the current employee.');
    }

    public function test_editing_a_form_preserves_unrepresented_metadata_and_reference_types(): void
    {
        $person = $this->person();
        $repo = app(ServiceRequestRequestRepository::class);
        $record = $repo->storeResource($this->resource(), $person->id);
        $form = $repo->formData($record);
        $form['note'] = 'Updated synthetic note';
        $repo->store($form, $person->id);
        $actual = $repo->getResource($record->id);
        $this->assertSame('Updated synthetic note', $actual['note']);
        $this->assertSame($this->resource()['code'], $actual['code']);
        $this->assertSame($this->resource()['category'], $actual['category']);
        $this->assertSame($this->resource()['basedOn'], $actual['basedOn']);
        $this->assertSame($this->resource()['occurrencePeriod'], $actual['occurrencePeriod']);
    }

    public function test_clearing_an_ordered_collection_does_not_delete_other_collections(): void
    {
        $person = $this->person();
        $repo = app(ServiceRequestRequestRepository::class);
        $resource = $this->resource();
        $repo->storeResource($resource, $person->id);
        $resource['supportingInfo'] = [];
        $record = $repo->storeResource($resource, $person->id);
        $actual = $repo->getResource($record);
        $this->assertSame([], $actual['supportingInfo']);
        $this->assertCount(2, $actual['reasonReference']);
        $this->assertCount(2, $actual['basedOn']);
    }

    public function test_cross_patient_uuid_cannot_overwrite_a_resource(): void
    {
        $repo = app(ServiceRequestRequestRepository::class);
        $record = $repo->storeResource($this->resource(), $this->person()->id);
        try {
            $repo->storeResource($this->resource(), $this->person()->id);
            $this->fail('Cross-patient identity must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame($record->personId, ServiceRequestRequest::findOrFail($record->id)->personId);
        }
    }

    public function test_over_precision_quantity_rolls_back_the_entire_import(): void
    {
        $resource = $this->resource();
        $resource['quantity']['value'] = '1.12345678901';
        try {
            app(ServiceRequestRequestRepository::class)->storeResource($resource, $this->person()->id);
            $this->fail('Precision must not be silently lost.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseMissing('service_request_requests', ['uuid' => $resource['id']]);
        }
    }
    public function test_exponent_and_large_decimal_precision_are_never_silently_lost(): void
    {
        $repo = app(ServiceRequestRequestRepository::class);
        $person = $this->person();
        foreach (['1e-20', '13123456789012.1234567890'] as $value) {
            $resource = $this->resource();
            $resource['quantity']['value'] = $value;
            try {
                $repo->storeResource($resource, $person->id);
                $this->fail('Unsupported numeric precision must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseMissing('service_request_requests', ['uuid' => $resource['id']]);
            }
        }
        $resource['quantity']['value'] = 1e-10;
        $record = $repo->storeResource($resource, $person->id);
        $this->assertSame(1e-10, $repo->getResource($record)['quantity']['value']);
    }

    public function test_occurrence_datetime_retains_the_original_timezone_and_precision(): void
    {
        $resource = $this->resource();
        unset($resource['occurrencePeriod']);
        $resource['occurrenceDateTime'] = '2026-11-01T12:20:30.000+02:00';
        $repo = app(ServiceRequestRequestRepository::class);
        $record = $repo->storeResource($resource, $this->person()->id);
        $this->assertSame($resource['occurrenceDateTime'], $repo->getResource($record)['occurrenceDateTime']);
    }

    public function test_preflight_blocks_registered_legacy_rows_without_writing(): void
    {
        $person = $this->person();
        $record = ServiceRequestRequest::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active', 'service_id' => 'synthetic-service', 'quantity' => 1]);
        $repo = app(ServiceRequestRequestRepository::class);
        $report = $repo->backfillResources($person->id);
        $this->assertSame([$record->id], $report['needsRemoteSync']);
        $this->assertSame(0, $report['converted']);
        $this->assertNull($record->fresh()->fhirVersion);
        $this->assertSame($report, $repo->backfillResources($person->id, true));
    }

    public function test_preflight_reports_ambiguous_drafts_instead_of_inventing_author_and_units(): void
    {
        $person = $this->person();
        $record = ServiceRequestRequest::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'draft', 'service_id' => 'synthetic-service', 'quantity' => 1]);
        $report = app(ServiceRequestRequestRepository::class)->backfillResources($person->id, true);
        $this->assertCount(1, $report['errors']);
        $this->assertSame(0, $report['converted']);
        $this->assertNull($record->fresh()->fhirVersion);
    }
    public function test_execution_progress_never_becomes_the_canonical_clinical_status(): void
    {
        $repo = app(ServiceRequestRequestRepository::class);
        $record = $repo->storeResource($this->resource(), $this->person()->id);
        $repo->applyExecutionResult($record, ['status' => 'processed'], null);
        $actual = $repo->getResource($record);
        $this->assertSame('active', $actual['status']);
        $this->assertSame('in_progress', $actual['programProcessingStatus']);
        $repo->updateClinicalStatus($record, 'recalled');
        $this->assertSame('recalled', $repo->getResource($record)['status']);
    }

}
