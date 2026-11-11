<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Permission;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mockery;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\TestCase;

class ReferralSignPayloadTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;

    protected Person $person;

    protected Encounter $encounter;

    protected Employee $employee;

    protected LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        Model::preventAccessingMissingAttributes();

        $party = Party::create([
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
            'legal_entity_type_id' => DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
                ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']),
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
        $user->employees()->syncWithoutDetaching([$this->employee->id]);
        $this->instance('legalEntity', $this->legalEntity);
        $this->grantReferralEmployeeRole($user, $this->employee);
        foreach (['service_request:write', 'care_plan:write'] as $scope) {
            $user->givePermissionToParent(Permission::findOrCreate($scope, 'web'));
        }
        $this->actingAs($user);

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

    public function test_encounter_group_referral_keeps_group_identity_in_storage_and_sign_content(): void
    {
        $groupId = (string) Str::uuid();
        $employee = [
            'employee_id' => $this->employee->id, 'employee_uuid' => $this->employee->uuid,
            'legal_entity_uuid' => $this->legalEntity->uuid,
        ];
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $uuid = $lifecycle->createEncounterDraft($this->encounter, [
            'quantity' => 2,
            'kind' => 'service_request', 'service_id' => $groupId, 'service_type' => 'service_group',
            'category' => 'laboratory_procedure', 'started_at' => '2026-11-01T10:00:00Z',
            'ended_at' => '2026-11-02T10:00:00Z',
        ], $employee);
        $record = MedicalEventsRepository::serviceRequest()->findByUuid($uuid);
        $resource = MedicalEventsRepository::serviceRequest()->getResource($record);
        $this->assertSame($groupId, data_get($resource, 'code.identifier.value'));
        $this->assertSame('service_group', data_get($resource, 'code.identifier.type.coding.0.code'));
        $form = $lifecycle->buildSignDbData($record, null, null, $employee);
        $content = ServiceRequestForm::buildCreateContent(Fhir::serviceRequest()->toFhir($form, $employee));
        $this->assertSame($groupId, data_get($content, 'code.identifier.value'));
        $this->assertSame('service_group', data_get($content, 'code.identifier.type.coding.0.code'));
    }

    public function test_encounter_draft_keeps_subsecond_occurrence_before_persistence(): void
    {
        $period = ['start' => '2026-11-01T10:20:30.123456Z', 'end' => '2026-11-02T10:20:30.654321Z'];
        $uuid = app(ReferralRequestLifecycleService::class)->createEncounterDraft($this->encounter, ['quantity' => 1.25, 'kind' => 'service_request', 'service_id' => (string) Str::uuid(), 'started_at' => $period['start'], 'ended_at' => $period['end'], 'category' => 'diagnostic_procedure'], ['employee_id' => $this->employee->id]);
        $actual = MedicalEventsRepository::serviceRequest()->getResource(MedicalEventsRepository::serviceRequest()->findByUuid($uuid));
        $this->assertSame($period, $actual['occurrencePeriod']);
    }

    public function test_normalized_service_group_activity_creates_and_signs_a_lossless_referral(): void
    {
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $planResource['status'] = 'active';
        $planResource['author']['identifier']['value'] = $this->employee->uuid;
        $planResource['encounter']['identifier']['value'] = $this->encounter->uuid;
        $plan = Repository::carePlan()->storeResource($planResource, $this->person->id, $this->legalEntity->id);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['detail']['kind'] = 'service_request';
        $resource['detail']['productReference']['identifier']['type']['coding'][0]['code'] = 'service_group';
        $resource['detail']['quantity'] = ['value' => 1.2345, 'system' => 'SERVICE_UNIT', 'code' => 'PROCEDURE'];
        $resource['detail']['remainingQuantity'] = $resource['detail']['quantity'];
        unset($resource['detail']['dailyAmount']);
        $programId = '10050000-0000-4000-8000-000000000099';
        $resource['detail']['program'] = ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'medical_program']]], 'value' => $programId]];
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([['program_id' => $programId, 'program_name' => 'Synthetic program', 'status' => 'VALID']]);
        $api->shouldReceive('prequalify')->once()->with($this->person->uuid, Mockery::on(fn ($payload) => data_get($payload, 'programs.0.identifier.value') === $programId && data_get($payload, 'service_request.code.identifier.type.coding.0.code') === 'service_group'))->andReturn($response);
        $this->instance(ServiceRequestApi::class, $api);
        $activity = Repository::carePlanActivity()->storeResource($resource, $plan);
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $employee = ['employee_id' => $this->employee->id, 'division_id' => null, 'employee_uuid' => $this->employee->uuid, 'legal_entity_uuid' => $this->legalEntity->uuid];
        $uuid = $lifecycle->createCarePlanDraft($plan, ['activity_id' => $activity->id, 'kind' => 'service_request', 'program_id' => null, 'priority' => 'routine', 'category' => 'diagnostic_procedure', 'intent' => 'order', 'started_at' => '2026-11-01T10:00:00.123456Z', 'ended_at' => '2026-11-02T10:00:00.654321Z'], 0.123456, $employee);
        $record = MedicalEventsRepository::serviceRequest()->findByUuid($uuid);
        $actual = MedicalEventsRepository::serviceRequest()->getResource($record);
        $this->assertSame('service_group', data_get($actual, 'code.identifier.type.coding.0.code'));
        $this->assertSame(0.123456, $actual['quantity']['value']);
        $this->assertSame('2026-11-01T10:00:00.123456Z', $actual['occurrencePeriod']['start']);
        $this->assertSame('2026-11-02T10:00:00.654321Z', $actual['occurrencePeriod']['end']);
        $this->assertSame('PROCEDURE', $actual['quantity']['code']);
        $this->assertSame($programId, data_get($actual, 'program.identifier.value'));
        $this->assertSame(['care_plan', 'activity'], array_map(fn ($ref) => data_get($ref, 'identifier.type.coding.0.code'), $actual['basedOn']));
        $form = $lifecycle->buildSignDbData($record, $activity, $plan, $employee);
        $content = ServiceRequestForm::buildCreateContent(Fhir::serviceRequest()->toFhir($form, $employee));
        $this->assertSame('service_group', data_get($content, 'code.identifier.type.coding.0.code'));
        $this->assertSame($actual['quantity'], $content['quantity']);
        $this->assertSame($actual['occurrencePeriod'], $content['occurrence_period']);
    }

    public function test_legacy_draft_cannot_be_signed_using_invented_units_or_author(): void
    {
        $record = $this->createDraft();
        $record->forceFill(['detailsSyncedAt' => null])->save();
        $this->expectException(InvalidArgumentException::class);
        app(ReferralRequestLifecycleService::class)->buildSignDbData($record, null, $this->encounter, ['employee_id' => $this->employee->id]);
    }

    public function test_recoverable_draft_conversion_is_explicit_and_repeatable(): void
    {
        $record = $this->createDraft();
        $record->forceFill(['detailsSyncedAt' => null, 'quantity' => null])->save();
        $repo = MedicalEventsRepository::serviceRequest();
        $preflight = $repo->backfillResources($this->person->id);
        $this->assertSame([], $preflight['errors']);
        $this->assertSame(0, $preflight['converted']);
        $this->assertNull($record->fresh()->detailsSyncedAt);
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

        app(ReferralRequestLifecycleService::class)->saveServiceRequest($data, $this->person->id, ['person_uuid' => $this->person->uuid, 'employee_uuid' => $this->employee->uuid, 'legal_entity_uuid' => $this->legalEntity->uuid]);

        return ServiceRequestRequest::query()
            ->with(['basedOn', 'context', 'intent', 'category', 'priority'])
            ->where('uuid', $data['uuid'])
            ->firstOrFail();
    }

    public function test_uuid_author_context_is_saved_in_an_ordinary_referral_and_survives_a_partial_receipt(): void
    {
        $this->instance('legalEntity', $this->legalEntity);
        $uuid = app(ReferralRequestLifecycleService::class)->createEncounterDraft($this->encounter, [
            'quantity' => 1,
            'kind' => 'service_request', 'service_id' => (string) Str::uuid(),
            'category' => 'diagnostic_procedure', 'started_at' => '2026-11-01T10:20:30Z', 'ended_at' => '2026-11-02T10:20:30Z',
        ], ['employee_uuid' => $this->employee->uuid, 'legal_entity_uuid' => $this->legalEntity->uuid]);
        $repository = MedicalEventsRepository::serviceRequest();
        $record = $repository->findByUuid($uuid);
        $this->assertSame($this->employee->id, $record->employeeId);
        $this->assertSame($this->employee->uuid, $record->requesterEmployee->value);
        $this->assertSame($this->legalEntity->uuid, $record->requesterLegalEntity->value);
        $resource = $repository->getResource($record);
        $this->assertSame('employee', data_get($resource, 'requesterEmployee.identifier.type.coding.0.code'));
        $signing = Fhir::serviceRequest()->toCreateSignedContent($repository->formData($record), ['resource' => $resource]);
        $this->assertSame($this->employee->uuid, data_get($signing, 'requester_employee.identifier.value'));
        $link = $repository->beginSubmission($record, ['status' => 'active']);
        $repository->finishSubmission($link, ['status' => 'processed', 'result' => ['id' => $uuid, 'status' => 'active']]);
        $this->assertSame($this->employee->id, $record->fresh()->employeeId);
        $this->assertSame($resource['requesterEmployee'], $repository->getResource($record->fresh())['requesterEmployee']);
    }

    public function test_a_local_referral_without_a_resolvable_author_is_not_persisted(): void
    {
        $before = ServiceRequestRequest::count();
        try {
            app(ReferralRequestLifecycleService::class)->createEncounterDraft($this->encounter, [
                'quantity' => 1,
                'kind' => 'service_request', 'service_id' => (string) Str::uuid(), 'category' => 'diagnostic_procedure',
                'started_at' => '2026-11-01T10:20:30Z', 'ended_at' => '2026-11-02T10:20:30Z',
            ], ['employee_uuid' => (string) Str::uuid(), 'legal_entity_uuid' => $this->legalEntity->uuid]);
            $this->fail('Application-created referrals must not silently lose their author.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('encounterReferralForm', $exception->errors());
            $this->assertSame($before, ServiceRequestRequest::count());
        }
    }

    public function test_care_plan_referral_keeps_the_uuid_author_context_without_a_local_id(): void
    {
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $planResource['period'] = ['start' => '2026-11-01T10:00:00Z', 'end' => '2026-11-10T10:00:00Z'];
        $plan = Repository::carePlan()->storeResource($planResource, $this->person->id, $this->legalEntity->id);
        $activityResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $activityResource['detail']['kind'] = 'service_request';
        $activityResource['detail']['quantity'] = ['value' => 10, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE'];
        $activityResource['detail']['remainingQuantity'] = ['value' => 10, 'system' => 'SERVICE_UNIT', 'code' => 'PIECE'];
        $activityResource['detail']['scheduledPeriod'] = $planResource['period'];
        $activityResource['detail']['productReference']['identifier']['type']['coding'][0]['code'] = 'service';
        $activity = Repository::carePlanActivity()->storeResource($activityResource, $plan);
        $uuid = app(ReferralRequestLifecycleService::class)->createCarePlanDraft($plan, [
            'activity_id' => $activity->id, 'kind' => 'service_request', 'category' => 'diagnostic_procedure',
            'started_at' => '2026-11-02T10:00:00Z', 'ended_at' => '2026-11-03T10:00:00Z', 'priority' => 'routine',
        ], 1, ['employee_uuid' => $this->employee->uuid, 'legal_entity_uuid' => $this->legalEntity->uuid], prequalify: false);
        $record = MedicalEventsRepository::serviceRequest()->findByUuid($uuid);
        $this->assertSame($this->employee->id, $record->employeeId);
        $this->assertSame($this->employee->uuid, $record->requesterEmployee->value);
        $this->assertSame($this->legalEntity->uuid, $record->requesterLegalEntity->value);
        $this->assertSame($activity->uuid, $record->basedOn->value);
        $this->assertArrayNotHasKey('_fhirContext', MedicalEventsRepository::serviceRequest()->getResource($record));
    }
}
