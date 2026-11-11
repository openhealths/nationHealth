<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Livewire\DiagnosticReport\DiagnosticReportCreate;
use App\Livewire\EncounterPackage\EncounterPackageComponent;
use App\Livewire\Procedure\ProcedureCreate;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
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
        $repo = Repository::serviceRequest();
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
        $repo = Repository::serviceRequest();
        $record = $repo->storeResource($this->resource(), $person->id);
        $form = $repo->formData($record);
        $form['note'] = 'Updated synthetic note';
        $repo->store((new ServiceRequestMapper())->toFhir($form, ['resource' => $repo->getResource($record)]), $person->id);
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
        $repo = Repository::serviceRequest();
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
        $repo = Repository::serviceRequest();
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
            Repository::serviceRequest()->storeResource($resource, $this->person()->id);
            $this->fail('Precision must not be silently lost.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseMissing('service_request_requests', ['uuid' => $resource['id']]);
        }
    }
    public function test_exponent_and_large_decimal_precision_are_never_silently_lost(): void
    {
        $repo = Repository::serviceRequest();
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

    public function test_occurrence_datetime_retains_the_instant_and_precision(): void
    {
        $resource = $this->resource();
        unset($resource['occurrencePeriod']);
        $resource['occurrenceDateTime'] = '2026-11-01T12:20:30.000+02:00';
        $repo = Repository::serviceRequest();
        $record = $repo->storeResource($resource, $this->person()->id);
        $this->assertSame('2026-11-01T10:20:30.000000Z', $repo->getResource($record)['occurrenceDateTime']);
    }

    public function test_preflight_blocks_registered_legacy_rows_without_writing(): void
    {
        $person = $this->person();
        $record = ServiceRequestRequest::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'active', 'service_id' => 'synthetic-service', 'quantity' => 1]);
        $repo = Repository::serviceRequest();
        $report = $repo->backfillResources($person->id);
        $this->assertSame([$record->id], $report['needsRemoteSync']);
        $this->assertSame(0, $report['converted']);
        $this->assertNull($record->fresh()->detailsSyncedAt);
        $this->assertSame($report, $repo->backfillResources($person->id, true));
    }

    public function test_preflight_reports_ambiguous_drafts_instead_of_inventing_author_and_units(): void
    {
        $person = $this->person();
        $record = ServiceRequestRequest::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'draft', 'service_id' => 'synthetic-service', 'quantity' => 1]);
        $report = Repository::serviceRequest()->backfillResources($person->id, true);
        $this->assertCount(1, $report['errors']);
        $this->assertSame(0, $report['converted']);
        $this->assertNull($record->fresh()->detailsSyncedAt);
    }
    public function test_execution_progress_never_becomes_the_canonical_clinical_status(): void
    {
        $repo = Repository::serviceRequest();
        $record = $repo->storeResource($this->resource(), $this->person()->id);
        $repo->applyExecutionResult($record, ['status' => 'processed'], null);
        $actual = $repo->getResource($record);
        $this->assertSame('active', $actual['status']);
        $this->assertSame('in_progress', $actual['programProcessingStatus']);
        $repo->updateClinicalStatus($record, 'recalled');
        $this->assertSame('recalled', $repo->getResource($record)['status']);
    }

    public function test_end_only_occurrence_period_survives_the_complete_migration_sequence(): void
    {
        $resource = $this->resource();
        $repo = Repository::serviceRequest();
        $record = $repo->storeResource($resource, $this->person()->id);
        $resource['occurrencePeriod'] = ['end' => '2026-11-01T23:59:59.123456Z'];
        $repo->storeResource($resource, $record->personId);
        $record->refresh();
        $this->assertSame($resource['occurrencePeriod'], $repo->getResource($record)['occurrencePeriod']);
        $this->assertNull($record->occurrencePeriod->getRawOriginal('start'));
    }

    public static function clinicalReferralComponents(): array
    {
        return [
            'procedure' => [ProcedureCreate::class],
            'diagnostic report' => [DiagnosticReportCreate::class],
            'encounter package' => [EncounterPackageComponent::class],
        ];
    }

    #[DataProvider('clinicalReferralComponents')]
    public function test_selected_referral_import_uses_full_details_in_each_clinical_component(string $componentClass): void
    {
        $person = $this->person();
        $resource = $this->resource();
        $resource['subject'] = ['identifier' => ['value' => $person->uuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'patient']]]]];
        $this->mockReferralDetails($person, $resource['id'], Arr::toSnakeCase($resource));
        $component = $this->referralSearchComponent($person, $resource['id'], $componentClass);

        $details = (new ReflectionMethod($component, 'getSearchedElectronicReferral'))->invoke($component, $resource['id']);
        (new ReflectionMethod($component, 'storeElectronicReferralsIfMissing'))->invoke($component, [['referral' => $details, 'employee' => new Employee()]]);

        $repo = Repository::serviceRequest();
        $record = $repo->findByUuid($resource['id']);
        $this->assertNotNull($record);
        $this->assertEquals($resource, $repo->getResource($record));
        $this->assertSame('service_group', data_get($repo->getResource($record), 'code.identifier.type.coding.0.code'));
    }

    public function test_already_local_selected_referral_does_not_require_another_remote_read(): void
    {
        $person = $this->person();
        $resource = $this->resource();
        $repo = Repository::serviceRequest();
        $record = $repo->storeResource($resource, $person->id);
        $this->mock(PatientServiceRequestApi::class)->shouldNotReceive('getById');
        $component = $this->referralSearchComponent($person, $resource['id']);

        (new ReflectionMethod($component, 'storeElectronicReferralIfMissing'))->invoke($component, $resource['id'], new Employee());

        $this->assertEquals($resource, $repo->getResource($record));
        $this->assertSame(1, ServiceRequestRequest::whereUuid($resource['id'])->count());
    }

    public function test_a_local_referral_of_another_patient_is_not_silently_skipped(): void
    {
        $resource = $this->resource();
        Repository::serviceRequest()->storeResource($resource, $this->person()->id);
        $this->mock(PatientServiceRequestApi::class)->shouldNotReceive('getById');
        $component = $this->referralSearchComponent($this->person(), $resource['id']);

        $this->expectException(InvalidArgumentException::class);
        (new ReflectionMethod($component, 'getSearchedElectronicReferral'))->invoke($component, $resource['id']);
    }

    public function test_a_uuid_not_in_the_search_results_cannot_be_imported(): void
    {
        $person = $this->person();
        $uuid = (string) Str::uuid();
        $this->mock(PatientServiceRequestApi::class)->shouldNotReceive('getById');
        $component = $this->referralSearchComponent($person, (string) Str::uuid());

        $this->assertNull((new ReflectionMethod($component, 'getSearchedElectronicReferral'))->invoke($component, $uuid));
        $this->assertDatabaseMissing('service_request_requests', ['uuid' => $uuid]);
    }

    public function test_single_item_detail_list_is_unwrapped_before_import(): void
    {
        $person = $this->person();
        $resource = $this->resource();
        $native = Arr::toSnakeCase($resource);
        $this->mockReferralDetails($person, $resource['id'], [$native]);
        $component = $this->referralSearchComponent($person, $resource['id']);

        $this->assertSame($native, (new ReflectionMethod($component, 'getSearchedElectronicReferral'))->invoke($component, $resource['id']));
    }

    public static function mismatchedReferralDetails(): array
    {
        return ['referral UUID' => ['id'], 'patient UUID' => ['subject']];
    }

    #[DataProvider('mismatchedReferralDetails')]
    public function test_mismatched_remote_details_are_rejected_before_import(string $field): void
    {
        $person = $this->person();
        $resource = $this->resource();
        $uuid = $resource['id'];
        if ($field === 'id') {
            $resource['id'] = (string) Str::uuid();
        } else {
            $resource['subject'] = ['identifier' => ['value' => (string) Str::uuid()]];
        }
        $this->mockReferralDetails($person, $uuid, Arr::toSnakeCase($resource));
        $component = $this->referralSearchComponent($person, $uuid);

        try {
            (new ReflectionMethod($component, 'storeElectronicReferralIfMissing'))->invoke($component, $uuid, new Employee());
            $this->fail('Mismatched referral identity must block import.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('service_request_requests', ['uuid' => $uuid]);
            $this->assertDatabaseMissing('service_request_requests', ['uuid' => $resource['id']]);
        }
    }

    public function test_failed_detail_read_does_not_import_the_display_summary(): void
    {
        $person = $this->person();
        $uuid = $this->resource()['id'];
        $component = $this->referralSearchComponent($person, $uuid);
        $this->mock(PatientServiceRequestApi::class)->shouldReceive('getById')->once()
            ->andThrow(new EHealthConnectionException('Synthetic remote failure'));

        try {
            (new ReflectionMethod($component, 'storeElectronicReferralIfMissing'))->invoke($component, $uuid, new Employee());
            $this->fail('A failed detail read must block import.');
        } catch (EHealthConnectionException) {
            $this->assertDatabaseMissing('service_request_requests', ['uuid' => $uuid]);
        }
    }

    private function referralSearchComponent(Person $person, string $uuid, string $componentClass = ProcedureCreate::class): object
    {
        $component = new $componentClass();
        $component->personId = $person->id;
        $component->patientUuid = $person->uuid;
        $component->electronicReferralSearchResults = [$uuid => [
            'id' => $uuid, 'requisition' => '0000-1111-2222-3333',
            'category' => 'Діагностична процедура', 'service' => ['id' => 'display-service'],
        ]];

        return $component;
    }

    private function mockReferralDetails(Person $person, string $uuid, array $details): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($details);
        $this->mock(PatientServiceRequestApi::class)->shouldReceive('getById')->once()
            ->with($person->uuid, $uuid, [], true)->andReturn($response);
    }
}
