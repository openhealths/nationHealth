<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use App\Repositories\CarePlanRepository;
use App\Repositories\CarePlanActivityRepository;
use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferralSignPayloadTest extends TestCase
{
    use DatabaseTransactions;

    protected Person $person;

    protected Encounter $encounter;

    protected Employee $employee;

    protected LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        Model::preventAccessingMissingAttributes();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
                ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']),
            'is_active' => true,
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'sr_sign_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'legal_entity_id' => $this->legalEntity->id,
            'party_id' => $party->id,
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'position' => 'Doctor',
            'is_active' => true,
            'start_date' => now()->toDateString(),
        ]);

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1990-05-05',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $identifierId = Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = CodeableConcept::create()->id;

        $this->encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);
    }

    public function test_build_sign_db_data_for_encounter_draft_defaults_service_units(): void
    {
        $request = $this->createDraft();
        $serviceId = $request->serviceId;

        $payload = app(ReferralRequestLifecycleService::class)->buildSignDbData(
            $request,
            null,
            $this->encounter,
            [
                'employee_id' => $this->employee->id,
                'division_id' => null,
                'employee_uuid' => $this->employee->uuid,
                'legal_entity_uuid' => $this->legalEntity->uuid,
            ]
        );

        $this->assertSame('SERVICE_UNIT', $payload['quantity_system']);
        $this->assertSame('PIECE', $payload['quantity_code']);
        $this->assertSame($serviceId, $payload['service_id']);
        $this->assertNull($payload['based_on_uuid']);
        $this->assertSame($this->encounter->uuid, $payload['context_uuid']);
        $this->assertSame($request->patientInstruction, $payload['patient_instruction']);
        $this->assertSame($request->informWith, $payload['inform_with']);
    }

    public function test_build_sign_db_data_takes_quantity_units_from_care_plan_activity(): void
    {
        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->toDateString(),
            'title' => 'Sign payload plan',
            'status' => 'active',
            'encounter_id' => $this->encounter->id,
        ]);

        $productId = (string) Str::uuid();
        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'service_request',
            'product_reference' => $productId,
            'quantity' => 2,
            'quantity_system' => 'SERVICE_UNIT',
            'quantity_code' => 'PROCEDURE',
        ]);

        $request = $this->createDraft([
            'based_on_uuid' => $activity->uuid,
            'quantity' => 2,
            'service_id' => $productId,
        ]);

        $payload = app(ReferralRequestLifecycleService::class)->buildSignDbData(
            $request,
            $activity,
            $carePlan,
            [
                'employee_id' => $this->employee->id,
                'division_id' => null,
                'employee_uuid' => $this->employee->uuid,
                'legal_entity_uuid' => $this->legalEntity->uuid,
            ]
        );

        $this->assertSame('SERVICE_UNIT', $payload['quantity_system']);
        $this->assertSame('PROCEDURE', $payload['quantity_code']);
        $this->assertSame($activity->productReference, $payload['service_id']);
        $this->assertSame($activity->uuid, $payload['based_on_uuid']);
    }

    public function test_encounter_draft_keeps_subsecond_occurrence_before_persistence(): void
    {
        $period = ['start' => '2026-11-01T10:20:30.123456Z', 'end' => '2026-11-02T10:20:30.654321Z'];
        $uuid = app(ReferralRequestLifecycleService::class)->createEncounterDraft($this->encounter, ['kind' => 'service_request', 'service_id' => (string) Str::uuid(), 'started_at' => $period['start'], 'ended_at' => $period['end'], 'category' => 'diagnostic_procedure'], 1.25, ['employee_id' => $this->employee->id]);
        $actual = Repository::serviceRequest()->getResource(Repository::serviceRequest()->findByUuid($uuid));
        $this->assertSame($period, $actual['occurrencePeriod']);
    }

    public function test_normalized_service_group_activity_creates_and_signs_a_lossless_referral(): void
    {
        if (!method_exists(CarePlanActivityRepository::class, 'getResource')) {
            $this->markTestSkipped('Requires the complete Care Plan FHIR module from PR #998.');
        }
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $planResource['status'] = 'active';
        $planResource['author']['identifier']['value'] = $this->employee->uuid;
        $planResource['encounter']['identifier']['value'] = $this->encounter->uuid;
        $plan = app(CarePlanRepository::class)->storeResource($planResource, $this->person->id, $this->legalEntity->id);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['detail']['kind'] = 'service_request';
        $resource['detail']['productReference']['identifier']['type']['coding'][0]['code'] = 'service_group';
        $resource['detail']['quantity'] = ['value' => 1.2345, 'system' => 'SERVICE_UNIT', 'code' => 'PROCEDURE'];
        $resource['detail']['remainingQuantity'] = $resource['detail']['quantity'];
        unset($resource['detail']['dailyAmount']);
        $programId = '10050000-0000-4000-8000-000000000099';
        $resource['detail']['program'] = ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'medical_program']]], 'value' => $programId]];
        $api = \Mockery::mock(ServiceRequestApi::class)->makePartial();
        $response = \Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn([['status' => 'VALID']]);
        $api->shouldReceive('prequalify')->once()->with($this->person->uuid, \Mockery::on(fn ($payload) => data_get($payload, 'programs.0.identifier.value') === $programId && data_get($payload, 'service_request.code.identifier.type.coding.0.code') === 'service_group'))->andReturn($response);
        $this->instance(ServiceRequestApi::class, $api);
        $activity = app(CarePlanActivityRepository::class)->storeResource($resource, $plan);
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $employee = ['employee_id' => $this->employee->id, 'division_id' => null, 'employee_uuid' => $this->employee->uuid, 'legal_entity_uuid' => $this->legalEntity->uuid];
        $uuid = $lifecycle->createCarePlanDraft($plan, ['activity_id' => $activity->id, 'kind' => 'service_request', 'program_id' => null, 'priority' => 'routine', 'category' => 'diagnostic_procedure', 'intent' => 'order', 'started_at' => '2026-11-01T10:00:00.123456Z', 'ended_at' => '2026-11-02T10:00:00.654321Z'], 0.123456, $employee);
        $record = Repository::serviceRequest()->findByUuid($uuid);
        $actual = Repository::serviceRequest()->getResource($record);
        $this->assertSame('service_group', data_get($actual, 'code.identifier.type.coding.0.code'));
        $this->assertSame(0.123456, $actual['quantity']['value']);
        $this->assertSame('2026-11-01T10:00:00.123456Z', $actual['occurrencePeriod']['start']);
        $this->assertSame('2026-11-02T10:00:00.654321Z', $actual['occurrencePeriod']['end']);
        $this->assertSame('PROCEDURE', $actual['quantity']['code']);
        $this->assertSame($programId, data_get($actual, 'program.identifier.value'));
        $this->assertSame(['care_plan', 'activity'], array_map(fn ($ref) => data_get($ref, 'identifier.type.coding.0.code'), $actual['basedOn']));
        $form = $lifecycle->buildSignDbData($record, $activity, $plan, $employee);
        $content = app(ServiceRequestApi::class)->buildCreateContent(Fhir::serviceRequest()->toFhir($form, $employee));
        $this->assertSame('service_group', data_get($content, 'code.identifier.type.coding.0.code'));
        $this->assertSame($actual['quantity'], $content['quantity']);
        $this->assertSame($actual['occurrencePeriod'], $content['occurrence_period']);
    }

    public function test_legacy_draft_cannot_be_signed_using_invented_units_or_author(): void
    {
        $record = $this->createDraft();
        $record->forceFill(['fhirVersion' => null])->save();
        $this->expectException(\InvalidArgumentException::class);
        app(ReferralRequestLifecycleService::class)->buildSignDbData($record, null, $this->encounter, ['employee_id' => $this->employee->id]);
    }

    public function test_recoverable_draft_conversion_is_explicit_and_repeatable(): void
    {
        $record = $this->createDraft();
        $record->forceFill(['fhirVersion' => null, 'quantity' => null])->save();
        $repo = Repository::serviceRequest();
        $preflight = $repo->backfillResources($this->person->id);
        $this->assertSame([], $preflight['errors']);
        $this->assertSame(0, $preflight['converted']);
        $this->assertNull($record->fresh()->fhirVersion);
        $this->assertSame(1, $repo->backfillResources($this->person->id, true)['converted']);
        $resource = $repo->getResource($record->id);
        $this->assertSame($record->uuid, $resource['id']);
        $this->assertSame($this->employee->uuid, data_get($resource, 'requesterEmployee.identifier.value'));
        $this->assertSame(0, $repo->backfillResources($this->person->id, true)['converted']);
        $this->assertSame($resource, $repo->getResource($record->id));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDraft(array $overrides = []): ServiceRequestRequest
    {
        $data = array_merge([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'status' => 'draft',
            'started_at' => '2026-08-12',
            'ended_at' => '2026-11-12',
            'service_id' => (string) Str::uuid(),
            'quantity' => 1,
            'intent' => 'order',
            'category' => 'diagnostic_procedure',
            'context_uuid' => $this->encounter->uuid,
            'priority' => 'routine',
            'note' => 'standalone',
            'patient_instruction' => 'take documents',
            'inform_with' => (string) Str::uuid(),
        ], $overrides);

        Repository::serviceRequest()->store($data, $this->person->id);

        return ServiceRequestRequest::query()
            ->with(['basedOn', 'context', 'intent', 'category', 'priority'])
            ->where('uuid', $data['uuid'])
            ->firstOrFail();
    }
}
