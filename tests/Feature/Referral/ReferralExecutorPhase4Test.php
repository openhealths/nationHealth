<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequestApi;
use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ReferralExecutorPhase4Test extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Employee $employee;

    protected Person $person;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'ref_p4_'.Str::random(6).'@example.com',
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
            'full_name' => 'Д-р Іван Петренко',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);
        $this->user->employees()->attach($this->employee->id);

        $this->grantMedicalEventAbilities($this->user);

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Олена',
            'last_name' => 'Коваль',
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $patientApi = Mockery::mock(PatientServiceRequestApi::class)->makePartial();
        $patientApi->shouldReceive('getById')->andReturnUsing(function (string $patientUuid, string $uuid): EHealthResponse {
            $record = ServiceRequestRequest::where('uuid', $uuid)->firstOrFail();

            return $this->remoteResponse([
                'id' => $uuid,
                'status' => 'active',
                'program_processing_status' => 'new',
                'subject' => ['identifier' => ['value' => $patientUuid]],
                'program' => ['identifier' => ['value' => $record->programId]],
            ]);
        })->byDefault();
        $this->instance(PatientServiceRequestApi::class, $patientApi);
    }

    public function test_qualify_sends_a_medical_program_identifier_before_use(): void
    {
        $record = $this->createActiveReferral();
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('qualify')->once()->ordered()->with($record->uuid, [
            'programs' => [[
                'identifier' => [
                    'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'medical_program']]],
                    'value' => $record->programId,
                ],
            ]],
        ])->andReturn($this->remoteResponse([['status' => 'VALID']]));
        $api->shouldReceive('process')->once()->ordered()->andReturn($this->remoteResponse(['status' => 'active']));
        $this->instance(ServiceRequestApi::class, $api);

        app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, $this->person->uuid);

        $this->assertSame('in_progress', $record->fresh()->status);
    }

    public function test_already_used_by_our_facility_skips_qualify_and_use_even_with_stale_local_status(): void
    {
        $record = $this->createActiveReferral();
        $remote = $this->remoteReferral($record, [
            'program_processing_status' => 'in_progress',
            'used_by_legal_entity' => ['identifier' => ['value' => $this->legalEntity->uuid]],
        ]);
        app(PatientServiceRequestApi::class)->shouldReceive('getById')->once()->andReturn($this->remoteResponse($remote));
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldNotReceive('qualify', 'process');
        $this->instance(ServiceRequestApi::class, $api);

        $result = app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, $this->person->uuid);

        $this->assertSame($remote, $result);
        $this->assertSame('in_progress', $record->fresh()->status);
    }

    public function test_another_facility_is_not_treated_as_already_prepared(): void
    {
        $record = $this->createActiveReferral();
        $record->update(['status' => 'in_progress']);
        app(PatientServiceRequestApi::class)->shouldReceive('getById')->once()->andReturn($this->remoteResponse(
            $this->remoteReferral($record, [
                'program_processing_status' => 'in_progress',
                'used_by_legal_entity' => ['identifier' => ['value' => (string) Str::uuid()]],
            ])
        ));
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('qualify')->once()->andReturn($this->remoteResponse([['status' => 'VALID']]));
        $api->shouldReceive('process')->once()->andThrow(new \RuntimeException('Reuse is temporarily blocked'));
        $this->instance(ServiceRequestApi::class, $api);

        $this->expectExceptionMessage('Reuse is temporarily blocked');
        app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, $this->person->uuid);
    }

    public function test_terminal_remote_referral_is_rejected_before_any_mutation(): void
    {
        $record = $this->createActiveReferral();
        app(PatientServiceRequestApi::class)->shouldReceive('getById')->once()->andReturn($this->remoteResponse(
            $this->remoteReferral($record, ['status' => 'recalled'])
        ));
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldNotReceive('qualify', 'process');
        $this->instance(ServiceRequestApi::class, $api);

        $this->expectExceptionMessage('Направлення недоступне для взяття в роботу.');
        app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, $this->person->uuid);
    }

    public function test_remote_patient_mismatch_is_rejected_before_any_mutation(): void
    {
        $record = $this->createActiveReferral();
        app(PatientServiceRequestApi::class)->shouldReceive('getById')->once()->andReturn($this->remoteResponse(
            $this->remoteReferral($record, ['subject' => ['identifier' => ['value' => (string) Str::uuid()]]])
        ));
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldNotReceive('qualify', 'process');
        $this->instance(ServiceRequestApi::class, $api);

        $this->expectException(\InvalidArgumentException::class);
        app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, $this->person->uuid);
    }

    public function test_local_patient_mismatch_does_not_even_fetch_remote_referral(): void
    {
        $record = $this->createActiveReferral();
        app(PatientServiceRequestApi::class)->shouldNotReceive('getById');

        $this->expectException(\InvalidArgumentException::class);
        app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, (string) Str::uuid());
    }

    private function createActiveReferral(): ServiceRequestRequest
    {
        return ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'employee_id' => $this->employee->id,
            'person_id' => $this->person->id, 'status' => 'active',
            'service_id' => '59300-00', 'quantity' => 1, 'program_id' => (string) Str::uuid(),
        ]);
    }

    private function remoteReferral(ServiceRequestRequest $record, array $overrides = []): array
    {
        return array_replace([
            'id' => $record->uuid, 'status' => 'active', 'program_processing_status' => 'new',
            'subject' => ['identifier' => ['value' => $this->person->uuid]],
            'program' => ['identifier' => ['value' => $record->programId]],
        ], $overrides);
    }

    public function test_encounter_selection_prepares_once_and_repeated_selection_does_not_use_again(): void
    {
        $this->actingAs($this->user);
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('qualify')->once()->andReturn($this->remoteResponse([['status' => 'VALID']]));
        $api->shouldReceive('process')->once()->andReturn($this->remoteResponse(['status' => 'active']));
        $this->instance(ServiceRequestApi::class, $api);
        app(PatientServiceRequestApi::class)->shouldReceive('getById')->twice()->andReturn(
            $this->remoteResponse($this->remoteReferral($record)),
            $this->remoteResponse($this->remoteReferral($record, [
                'program_processing_status' => 'in_progress',
                'used_by_legal_entity' => ['identifier' => ['value' => $this->legalEntity->uuid]],
            ])),
        );
        $lifecycle = app(ReferralRequestLifecycleService::class);

        $component->selectElectronicReferral($record->uuid, $lifecycle);
        $this->assertSame($record->uuid, $component->confirmedElectronicReferralUuid);
        $component->selectElectronicReferral($record->uuid, $lifecycle);
        $this->assertSame($record->uuid, $component->confirmedElectronicReferralUuid);

        $validated = ['encounter' => ['referralType' => 'electronic', 'referralNumber' => $record->uuid]];
        $method = new \ReflectionMethod($component, 'resolveAllReferrals');
        $method->invokeArgs($component, [&$validated, true]);
        $this->assertSame($record->uuid, $validated['encounter']['referralNumber']);
    }

    public function test_failed_selection_clears_previous_confirmation_and_shows_a_field_error(): void
    {
        $this->actingAs($this->user);
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $component->confirmedElectronicReferralUuid = (string) Str::uuid();
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('takeIntoWork')->once()->andThrow(new \RuntimeException('Not allowed'));

        $component->selectElectronicReferral($record->uuid, $lifecycle);

        $this->assertNull($component->confirmedElectronicReferralUuid);
        $this->assertNull($component->selectedReferralUuid);
        $this->assertTrue($component->getErrorBag()->has('form.encounter.referralNumber'));
        $this->assertStringContainsString('Not allowed', session('error'));
    }

    public function test_client_prepared_uuid_does_not_bypass_confirmation(): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $component->preparedElectronicReferralUuid = $record->uuid;
        $validated = ['encounter' => ['referralType' => 'electronic', 'referralNumber' => $record->uuid]];
        $method = new \ReflectionMethod($component, 'resolveAllReferrals');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invokeArgs($component, [&$validated, true]);
    }

    public function test_local_referral_list_includes_in_progress_but_excludes_terminal_statuses(): void
    {
        $record = $this->createActiveReferral();
        $record->update(['status' => 'in_progress']);
        $terminal = $this->createActiveReferral();
        $terminal->update(['status' => 'completed']);
        $component = $this->encounterCreateComponent($record);

        (new \ReflectionMethod($component, 'loadAvailableReferrals'))->invoke($component);

        $this->assertSame([$record->uuid], array_column($component->availableReferrals, 'id'));
    }

    private function encounterCreateComponent(ServiceRequestRequest $record): \App\Livewire\Encounter\EncounterCreate
    {
        $component = new \App\Livewire\Encounter\EncounterCreate();
        $component->form = new \App\Livewire\Encounter\Forms\EncounterForm($component, 'form');
        $component->personId = $this->person->id;
        $component->patientUuid = $this->person->uuid;
        $component->availableReferrals = [['id' => $record->uuid, 'requisition' => '0000-1111-2222-3333']];

        return $component;
    }

    private function remoteResponse(array $data): EHealthResponse
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn($data);

        return $response;
    }

    public function test_take_into_work_blocks_when_qualify_fails(): void
    {
        $referralUuid = (string) Str::uuid();
        $programId = (string) Str::uuid();

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'program_id' => $programId,
            'priority' => 'routine',
        ]);

        $mockApi = Mockery::mock(ServiceRequestApi::class);
        $mockApi->shouldReceive('qualify')
            ->once()
            ->andThrow(new EHealthValidationException([
                'error' => ['message' => 'program not allowed'],
            ]));
        $mockApi->shouldReceive('process')->never();
        $this->app->instance(ServiceRequestApi::class, $mockApi);

        $service = app(ReferralRequestLifecycleService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Результати перевірки не дають змоги використати електронне направлення');

        $service->takeIntoWork($referralUuid, $this->employee, $this->person->uuid);
    }

    public function test_take_into_work_blocks_when_qualify_returns_invalid(): void
    {
        $referralUuid = (string) Str::uuid();
        $programId = (string) Str::uuid();

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'program_id' => $programId,
            'priority' => 'routine',
        ]);

        $mockApi = Mockery::mock(ServiceRequestApi::class);
        $qualifyResponse = Mockery::mock(EHealthResponse::class);
        $qualifyResponse->shouldReceive('getData')->andReturn([
            'data' => [
                ['status' => 'INVALID', 'rejection_reason' => 'limit exceeded'],
            ],
        ]);
        $mockApi->shouldReceive('qualify')
            ->once()
            ->andReturn($qualifyResponse);
        $mockApi->shouldReceive('process')->never();
        $this->app->instance(ServiceRequestApi::class, $mockApi);

        $service = app(ReferralRequestLifecycleService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Результати перевірки не дають змоги використати електронне направлення');

        $service->takeIntoWork($referralUuid, $this->employee, $this->person->uuid);
    }

    public function test_recall_updates_local_status_to_recalled(): void
    {
        $referralUuid = (string) Str::uuid();

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'priority' => 'routine',
        ]);

        $recallResponse = Mockery::mock(EHealthResponse::class);
        $recallResponse->shouldReceive('getData')->andReturn(['status' => 'recalled']);

        $mockPatientApi = Mockery::mock(PatientServiceRequestApi::class)->makePartial();
        $mockPatientApi->shouldReceive('recall')
            ->once()
            ->with($this->person->uuid, $referralUuid, Mockery::on(static function (array $payload): bool {
                return ($payload['explanatory_letter'] ?? '') === 'Пацієнт більше не потребує послуги';
            }))
            ->andReturn($recallResponse);
        $this->app->instance(PatientServiceRequestApi::class, $mockPatientApi);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->recallReferral($this->person->uuid, $referralUuid, [
            'explanatory_letter' => 'Пацієнт більше не потребує послуги',
        ]);

        $this->assertSame('recalled', $result['status']);
        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $referralUuid,
            'status' => 'recalled',
        ]);
    }

    public function test_complete_referral_builds_based_on_for_encounter(): void
    {
        $referralUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;
        $episodeId = Identifier::create(['value' => (string) Str::uuid()])->id;

        Encounter::create([
            'uuid' => $encounterUuid,
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $episodeId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);

        $completeResponse = Mockery::mock(EHealthResponse::class);
        $completeResponse->shouldReceive('getData')->andReturn(['status' => 'completed']);

        $mockApi = Mockery::mock(ServiceRequestApi::class);
        $mockApi->shouldReceive('complete')
            ->once()
            ->with($referralUuid, Mockery::on(static function (array $payload) use ($encounterUuid): bool {
                return data_get($payload, 'based_on.0.identifier.type.coding.0.code') === 'encounter'
                    && data_get($payload, 'based_on.0.identifier.value') === $encounterUuid;
            }))
            ->andReturn($completeResponse);
        $this->app->instance(ServiceRequestApi::class, $mockApi);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->completeReferral($referralUuid, $encounterUuid, 'encounter');

        $this->assertSame('completed', $result['status']);
    }

    public function test_complete_referral_rejects_another_patients_encounter(): void
    {
        $referralUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        $stranger = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Other',
            'last_name' => 'Patient',
            'birth_date' => '1992-01-01',
            'gender' => 'FEMALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'in_progress',
            'service_id' => '37003-00',
            'intent' => 'order',
        ]);

        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;
        $episodeId = Identifier::create(['value' => (string) Str::uuid()])->id;

        Encounter::create([
            'uuid' => $encounterUuid,
            'person_id' => $stranger->id,
            'status' => 'finished',
            'episode_id' => $episodeId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.referral_complete_emz_mismatch'));

        app(ReferralRequestLifecycleService::class)->completeReferral($referralUuid, $encounterUuid, 'encounter');
    }

    public function test_referral_index_complete_requires_linked_encounter(): void
    {
        $this->actingAs($this->user);

        $referralUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        $incoming = Identifier::create(['value' => $referralUuid]);

        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;
        $episodeId = Identifier::create(['value' => (string) Str::uuid()])->id;

        Encounter::create([
            'uuid' => $encounterUuid,
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $episodeId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'incoming_referral_id' => $incoming->id,
            'ehealth_inserted_at' => now(),
        ]);

        $mockLifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $mockLifecycle->shouldReceive('completeReferral')
            ->once()
            ->with($referralUuid, $encounterUuid, 'encounter')
            ->andReturn(['status' => 'completed']);
        $this->instance(ReferralRequestLifecycleService::class, $mockLifecycle);

        Livewire::test(\App\Livewire\Referral\ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('searchResults', [[
                'id' => $referralUuid,
                'status' => 'in_progress',
                'subject' => [
                    'identifier' => ['value' => $this->person->uuid],
                ],
            ]])
            ->call('openCompleteModal', $referralUuid)
            ->assertSet('showCompleteModal', true)
            ->assertSet('selectedEmzType', 'encounter')
            ->set('selectedEmzUuid', $encounterUuid)
            ->call('confirmComplete')
            ->assertDispatched('notify');
    }

    public function test_search_results_translate_diagnostic_procedure_category(): void
    {
        $this->actingAs($this->user);

        Livewire::test(\App\Livewire\Referral\ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('hasSearched', true)
            ->set('searchResults', [[
                'id' => (string) Str::uuid(),
                'status' => 'active',
                'category' => [
                    'coding' => [['code' => 'diagnostic_procedure']],
                ],
            ]])
            ->assertSee(__('care-plan.referral_category.diagnostic_procedure'))
            ->assertDontSee('diagnostic_procedure');
    }

    public function test_complete_modal_uses_ukrainian_emz_type_labels(): void
    {
        $this->actingAs($this->user);

        Livewire::test(\App\Livewire\Referral\ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('showCompleteModal', true)
            ->assertSee(__('care-plan.emz_type.encounter'))
            ->assertSee(__('care-plan.emz_type.procedure'))
            ->assertSee(__('care-plan.emz_type.diagnostic_report'))
            ->assertSee(__('care-plan.referral_complete_emz_empty'))
            ->assertDontSee('(encounter)')
            ->assertDontSee('incoming_referral');
    }

    public function test_mapper_includes_author_optional_fields(): void
    {
        $mapper = new ServiceRequestMapper();
        $authUuid = (string) Str::uuid();
        $conditionUuid = (string) Str::uuid();

        $payload = $mapper->toPrequalifyPayload(
            [
                'service_id' => '59300-00',
                'intent' => 'order',
                'category' => 'procedure',
                'quantity' => 1,
                'priority' => 'routine',
                'patient_instruction' => 'Підготуватися натще',
                'inform_with' => "{$authUuid}|OTP|+380501112233",
                'reason_reference' => [
                    ['type' => 'condition', 'uuid' => $conditionUuid],
                ],
            ],
            [
                'person_uuid' => $this->person->uuid,
                'employee_uuid' => $this->employee->uuid,
                'legal_entity_uuid' => $this->legalEntity->uuid,
                'encounter_uuid' => (string) Str::uuid(),
            ]
        );

        $sr = $payload['service_request'];
        $this->assertSame('Підготуватися натще', $sr['patient_instruction']);
        $this->assertSame(['auth_method_id' => $authUuid], $sr['inform_with']);
        $this->assertSame($conditionUuid, $sr['reason_reference'][0]['identifier']['value']);
        $this->assertSame('condition', $sr['reason_reference'][0]['identifier']['type']['coding'][0]['code']);
    }
}
