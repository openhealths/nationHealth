<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\Person\Records\PatientMedicationRequests;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class PatientMedicationRequestsPhase6Test extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Person $person;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Олена',
            'last_name' => 'Коваленко',
            'tax_id' => '1122334455',
            'birth_date' => '1985-02-02',
            'gender' => 'FEMALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'mr_p6_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
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
            'birth_date' => '2001-02-23',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);
        $this->person->names()->create([
            'first_name' => 'Пацієнт',
            'last_name' => 'Якийсь',
            'language' => 'uk',
        ]);

        $this->actingAs($this->user);
        $this->grantMedicalEventAbilities($this->user, ['medication_request_request:read']);
    }

    public function test_repository_filters_by_status_period_and_source(): void
    {
        $repo = app(MedicationRequestRepository::class);

        $repo->store([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'medication_id' => (string) Str::uuid(),
            'medication_qty' => 2,
            'started_at' => '2026-01-10',
            'ended_at' => '2026-02-10',
            'intent' => 'order',
            'category' => 'community',
            'source' => MedicationRequestRequest::SOURCE_LOCAL,
        ], $this->person->id);

        $repo->store([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'status' => 'draft',
            'medication_id' => (string) Str::uuid(),
            'medication_qty' => 1,
            'started_at' => '2026-03-01',
            'ended_at' => '2026-03-20',
            'intent' => 'order',
            'category' => 'community',
            'source' => MedicationRequestRequest::SOURCE_LOCAL,
        ], $this->person->id);

        $repo->upsertFromEHealth([
            'id' => (string) Str::uuid(),
            'status' => 'active',
            'medication_qty' => 1,
            'medication_info' => ['medication_name' => 'еHealth препарат'],
        ], $this->person->id);

        $active = $repo->searchByPersonId($this->person->id, [
            'status' => 'active',
            'started_at_from' => '2026-01-01',
            'started_at_to' => '2026-01-31',
            'source' => MedicationRequestRequest::SOURCE_LOCAL,
        ]);

        $this->assertCount(1, $active);
        $this->assertSame('active', strtolower((string) $active[0]['status']));

        $localOnly = $repo->searchByPersonId($this->person->id, [
            'source' => MedicationRequestRequest::SOURCE_LOCAL,
        ]);
        $this->assertCount(2, $localOnly);

        $ehealth = $repo->searchEHealthPrescriptionsByPersonId($this->person->id);
        $this->assertCount(1, $ehealth);
    }

    public function test_registry_row_maps_payload_name_and_camel_case_fields(): void
    {
        $uuid = (string) Str::uuid();
        app(MedicationRequestRepository::class)->store([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'request_number' => '0000-TEST-1234-ABCD',
            'medication_id' => (string) Str::uuid(),
            'medication_qty' => 7,
            'started_at' => '2026-08-12',
            'ended_at' => '2026-09-11',
            'intent' => 'order',
            'category' => 'community',
            'ehealth_payload' => [
                'category' => 'community',
                'medication_info' => [
                    'medication_name' => 'симвастатин 20 мг, Таблетка',
                ],
                'medical_program' => [
                    'name' => 'Рецептурні лікарські засоби',
                ],
            ],
        ], $this->person->id);

        $rows = app(MedicationRequestRepository::class)->searchByPersonId($this->person->id, [
            'source' => MedicationRequestRequest::SOURCE_LOCAL,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('0000-TEST-1234-ABCD', $rows[0]['requestNumber']);
        $this->assertSame('симвастатин 20 мг, Таблетка', $rows[0]['medicationName']);
        $this->assertSame('7', $rows[0]['medicationQty']);
        $this->assertSame('12.08.2026 — 11.09.2026', $rows[0]['periodLabel']);
        $this->assertSame('Рецептурні лікарські засоби', $rows[0]['programName']);
        $this->assertSame('Амбулаторно', $rows[0]['categoryLabel']);
        $this->assertSame('—', $rows[0]['basisLabel']);
        $this->assertNull($rows[0]['encounterId']);
        $this->assertSame('Активний', $rows[0]['statusLabel']);

        $encounterUuid = (string) Str::uuid();
        $context = Identifier::create(['value' => $encounterUuid]);
        $category = CodeableConcept::create(['text' => 'community']);
        $standalone = new MedicationRequestRequest([
            'uuid' => (string) Str::uuid(),
            'status' => 'active',
            'category_id' => $category->id,
            'context_id' => $context->id,
            'medication_qty' => 7,
        ]);
        $standalone->setRelation('category', $category);
        $standalone->setRelation('context', $context);

        $mapped = app(MedicationRequestRepository::class)->toPatientRegistryRow(
            $standalone,
            [],
            [$encounterUuid => 23]
        );
        $this->assertSame('Взаємодія', $mapped['basisLabel']);
        $this->assertSame(23, $mapped['encounterId']);
        $this->assertNull($mapped['carePlanId']);

        $activityUuid = (string) Str::uuid();
        $basedOn = Identifier::create(['value' => $activityUuid]);
        $fromPlan = new MedicationRequestRequest([
            'uuid' => (string) Str::uuid(),
            'status' => 'active',
            'category_id' => $category->id,
            'based_on_id' => $basedOn->id,
            'context_id' => $context->id,
            'medication_qty' => 7,
        ]);
        $fromPlan->setRelation('category', $category);
        $fromPlan->setRelation('basedOn', $basedOn);
        $fromPlan->setRelation('context', $context);

        $activityRow = (new CarePlanActivity())->forceFill(['id' => 42, 'uuid' => $activityUuid, 'care_plan_id' => 7]);
        $planMapped = app(MedicationRequestRepository::class)->toPatientRegistryRow(
            $fromPlan,
            [$activityUuid => $activityRow],
            [$encounterUuid => 9]
        );
        $this->assertSame('План лікування', $planMapped['basisLabel']);
        $this->assertSame(42, $planMapped['activityId']);
        $this->assertSame(7, $planMapped['carePlanId']);
        $this->assertSame(9, $planMapped['encounterId']);
    }

    public function test_switch_tab_resets_search_mode(): void
    {
        Livewire::test(PatientMedicationRequests::class, [
            'legalEntity' => $this->legalEntity,
            'person' => $this->person,
            'preperson' => null,
        ])
            ->set('isSearchMode', true)
            ->call('switchTab', 'prescriptions')
            ->assertSet('activeTab', 'prescriptions')
            ->assertSet('isSearchMode', false);
    }

    public function test_imported_request_stays_in_requests_tab_and_preserves_local_details(): void
    {
        $uuid = (string) Str::uuid();
        $repo = app(MedicationRequestRepository::class);
        $repo->store([
            'uuid' => $uuid, 'employee_id' => $this->employee->id,
            'status' => 'new', 'medication_id' => 'INN-1', 'medication_qty' => 7,
            'ehealth_payload' => ['medication_name' => 'Existing name'],
        ], $this->person->id);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn([['id' => $uuid, 'status' => 'active']]);
        $api = Mockery::mock(MedicationRequestApi::class);
        $api->shouldReceive('getRequestsBySearchParams')->once()->with($this->person->uuid, [])->andReturn($response);
        $this->instance(MedicationRequestApi::class, $api);

        Livewire::test(PatientMedicationRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person, 'preperson' => null])
            ->call('searchInEHealth')
            ->call('saveFromEHealth', $uuid)
            ->assertSet('medicationRequests.0.uuid', $uuid)
            ->assertSet('prescriptions', [])
            ->assertSet('isSearchMode', false);

        $request = $repo->findByUuid($uuid);
        $this->assertSame($this->employee->id, $request->employeeId);
        $this->assertSame('7.00', $request->medicationQty);
        $this->assertSame('INN-1', $request->medicationId);
        $this->assertSame('Existing name', $request->ehealthPayload['medication_name']);
        $this->assertSame(MedicationRequestRequest::SOURCE_LOCAL, $request->source);
    }

    public function test_partial_external_summary_does_not_invent_a_medication_quantity(): void
    {
        $request = app(MedicationRequestRepository::class)->upsertFromEHealth([
            'id' => (string) Str::uuid(), 'status' => 'active',
        ], $this->person->id);

        $this->assertNull($request->medicationQty);
        $this->assertNull($request->medicationId);
        $this->assertSame(MedicationRequestRequest::TYPE_PRESCRIPTION, $request->resourceType);
    }

    public function test_external_request_is_not_classified_as_a_signed_prescription(): void
    {
        $repo = app(MedicationRequestRepository::class);
        $request = $repo->upsertFromEHealth([
            'id' => (string) Str::uuid(), 'status' => 'new',
        ], $this->person->id, MedicationRequestRequest::TYPE_REQUEST);

        $this->assertSame(MedicationRequestRequest::SOURCE_EHEALTH, $request->source);
        $this->assertCount(1, $repo->searchByPersonId($this->person->id, ['resource_type' => MedicationRequestRequest::TYPE_REQUEST]));
        $this->assertSame([], $repo->searchEHealthPrescriptionsByPersonId($this->person->id));
    }

    public function test_refresh_replaces_supplied_dosage_arrays_without_retaining_removed_entries(): void
    {
        $repo = app(MedicationRequestRepository::class);
        $uuid = (string) Str::uuid();
        $repo->upsertFromEHealth([
            'id' => $uuid, 'status' => 'active', 'medication_name' => 'Existing name',
            'dosage_instructions' => [['text' => 'Old morning'], ['text' => 'Old evening']],
        ], $this->person->id);
        $request = $repo->upsertFromEHealth([
            'id' => $uuid, 'dosage_instructions' => [['text' => 'New instruction']],
        ], $this->person->id);

        $this->assertSame([['text' => 'New instruction']], $request->ehealthPayload['dosage_instructions']);
        $this->assertSame('Existing name', $request->ehealthPayload['medication_name']);
    }

    public function test_import_cannot_reassign_a_record_to_another_patient(): void
    {
        $repo = app(MedicationRequestRepository::class);
        $request = $repo->upsertFromEHealth(['id' => (string) Str::uuid(), 'status' => 'active'], $this->person->id);
        try {
            $repo->upsertFromEHealth(['id' => $request->uuid, 'status' => 'cancelled'], $this->person->id + 100);
            $this->fail('A cross-patient import must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertSame($this->person->id, $request->fresh()->personId);
            $this->assertSame('active', $request->fresh()->status);
        }
    }

    public function test_search_payload_cannot_be_replaced_by_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PatientMedicationRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person, 'preperson' => null])
            ->set('eHealthResults', [['id' => (string) Str::uuid(), 'status' => 'active']]);
    }

    public function test_active_tab_cannot_be_replaced_by_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PatientMedicationRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person, 'preperson' => null])
            ->set('activeTab', 'prescriptions');
    }

    public function test_reimport_reclassifies_historical_cache_without_duplicating_uuid(): void
    {
        $repo = app(MedicationRequestRepository::class);
        $uuid = (string) Str::uuid();
        $historical = $repo->upsertFromEHealth(['id' => $uuid, 'status' => 'active'], $this->person->id);
        $this->assertSame(MedicationRequestRequest::TYPE_PRESCRIPTION, $historical->resourceType);

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn([['id' => $uuid, 'status' => 'new']]);
        $api = Mockery::mock(MedicationRequestApi::class);
        $api->shouldReceive('getRequestsBySearchParams')->once()->with($this->person->uuid, [])->andReturn($response);
        $this->instance(MedicationRequestApi::class, $api);

        Livewire::test(PatientMedicationRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person, 'preperson' => null])
            ->assertSee(__('medication-requests.legacy_import_note'))
            ->assertSee(__('medication-requests.refresh_from_ehealth'))
            ->call('searchInEHealth')
            ->call('saveFromEHealth', $uuid)
            ->assertSet('medicationRequests.0.uuid', $uuid)
            ->assertSet('prescriptions', []);

        $this->assertSame(1, MedicationRequestRequest::where('uuid', $uuid)->count());
        $this->assertSame($historical->id, $repo->findByUuid($uuid)->id);
        $this->assertSame(MedicationRequestRequest::TYPE_REQUEST, $historical->fresh()->resourceType);
    }

    public function test_search_requires_the_resource_read_permission(): void
    {
        $this->user->revokePermissionTo('medication_request_request:read');
        Livewire::test(PatientMedicationRequests::class, ['legalEntity' => $this->legalEntity, 'person' => $this->person, 'preperson' => null])
            ->call('searchInEHealth')->assertForbidden();
    }
}
