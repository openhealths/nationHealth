<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestApi;
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

        $mockApi = Mockery::mock('alias:'.ServiceRequestApi::class);
        $mockApi->shouldReceive('qualify')
            ->once()
            ->andThrow(new EHealthValidationException([
                'error' => ['message' => 'program not allowed'],
            ]));
        $mockApi->shouldReceive('process')->never();

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

        $mockApi = Mockery::mock('alias:'.ServiceRequestApi::class);
        $mockApi->shouldReceive('qualify')
            ->once()
            ->andReturn([
                'data' => [
                    ['status' => 'INVALID', 'rejection_reason' => 'limit exceeded'],
                ],
            ]);
        $mockApi->shouldReceive('process')->never();

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

        $mockApi = Mockery::mock('alias:'.ServiceRequestApi::class);
        $mockApi->shouldReceive('recall')
            ->once()
            ->with($this->person->uuid, $referralUuid, Mockery::on(static function (array $payload): bool {
                return ($payload['explanatory_letter'] ?? '') === 'Пацієнт більше не потребує послуги';
            }))
            ->andReturn(['status' => 'recalled']);

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

        $mockApi = Mockery::mock('alias:'.ServiceRequestApi::class);
        $mockApi->shouldReceive('complete')
            ->once()
            ->with($referralUuid, Mockery::on(static function (array $payload) use ($encounterUuid): bool {
                return data_get($payload, 'based_on.0.identifier.type.coding.0.code') === 'encounter'
                    && data_get($payload, 'based_on.0.identifier.value') === $encounterUuid;
            }))
            ->andReturn(['status' => 'completed']);

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
