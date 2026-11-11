<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequestApi;
use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\EncounterPackage\EncounterPackageCreate;
use App\Livewire\EncounterPackage\Forms\EncounterForm;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;

use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Policies\EncounterPolicy;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\ClinicalEncounterView;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\Support\ReferralIndexFixture;
use Tests\TestCase;

class ReferralExecutorPhase4Test extends TestCase
{
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    public function test_search_results_display_initials_age_and_priority_for_both_program_states(): void
    {
        $this->actingAs($this->user);
        $rows = [];
        foreach (['new' => 'stat', 'in_progress' => 'routine'] as $state => $priority) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'status' => 'active', 'priority' => $priority,
                'program_processing_status' => $state,
                'category' => ['coding' => [['code' => 'counselling']]],
                'identity' => ['last_name' => 'Тестовий', 'first_name' => 'Андрій', 'second_name' => 'Іванович', 'age' => 0],
            ];
        }
        Livewire::test(ReferralIndexFixture::class, ['legalEntity' => $this->legalEntity])
            ->set('hasSearched', true)->call('seedResults', $rows)
            ->assertSee('Тестовий А. І.')->assertSee('0 повних років')
            ->assertSee(__('referrals.priorities.stat'))->assertSee(__('referrals.priorities.routine'));
    }

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

        $party = Party::create([
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

        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

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
            'employee_type' => 'SPECIALIST',
            'status' => 'APPROVED',
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);
        $this->user->employees()->attach($this->employee->id);

        $this->grantReferralEmployeeRole($this->user, $this->employee);
        $this->grantMedicalEventAbilities($this->user);
        $this->actingAs($this->user);

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Олена',
            'last_name' => 'Коваль',
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        foreach ([ServiceDictionary::KEY, MedicalProgramDictionary::KEY] as $key) {
            Cache::forever($key, []);
            Cache::forever($key.':fresh', true);
        }

        Cache::forever(BasicDictionary::KEY, [
            ['name' => 'eHealth/encounter_classes', 'values' => [['code' => 'AMB', 'description' => 'Ambulatory']]],
            ['name' => 'eHealth/encounter_types', 'values' => [['code' => 'AMB', 'description' => 'Ambulatory']]],
        ]);
        Cache::forever(BasicDictionary::KEY.':fresh', true);
        $this->partialMock(EncounterPolicy::class)->shouldReceive('view')->andReturn(Response::allow());

        $patientApi = Mockery::mock(PatientServiceRequestApi::class)->makePartial();
        $patientApi->shouldReceive('getById')->andReturnUsing(function (string $patientUuid, string $uuid): EHealthResponse {
            $record = ServiceRequestRequest::where('uuid', $uuid)->firstOrFail();

            return $this->remoteResponse($this->remoteReferral($record));
        })->byDefault();
        $this->instance(PatientServiceRequestApi::class, $patientApi);
    }

    public function test_care_plan_scope_uses_the_typed_collection_and_keeps_legacy_access(): void
    {
        $organization = Identifier::create(['value' => $this->legalEntity->uuid]);
        $plan = CarePlan::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'author_id' => $this->employee->id, 'legal_entity_id' => $this->legalEntity->id, 'period_start' => '2026-10-01', 'title' => 'Synthetic scope plan', 'status' => 'active', 'managing_organization_id' => $organization->id]);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['basedOn'][0]['identifier']['value'] = $plan->uuid;
        $repo = MedicalEventsRepository::serviceRequest();
        $record = $repo->storeResource($resource, $this->person->id);
        $this->assertTrue(ServiceRequestRequest::basedOnCarePlanOfLegalEntity($this->person)->whereKey($record->id)->exists());
        $resource['basedOn'][0]['identifier']['type']['coding'][0]['code'] = 'encounter';
        $repo->storeResource($resource, $this->person->id);
        $this->assertFalse(ServiceRequestRequest::basedOnCarePlanOfLegalEntity($this->person)->whereKey($record->id)->exists());
        $legacy = $this->createActiveReferral();
        $legacy->forceFill(['basedOnId' => Identifier::create(['value' => $plan->uuid])->id])->save();
        $this->assertTrue(ServiceRequestRequest::basedOnCarePlanOfLegalEntity($this->person)->whereKey($legacy->id)->exists());
    }

    public function test_qualify_sends_a_medical_program_identifier_before_use(): void
    {
        $record = $this->createActiveReferral();
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $api->shouldReceive('qualify')->once()->ordered()->with($record->uuid, [
            'programs' => [[
                'identifier' => [
                    'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'medical_program']]],
                    'value' => $record->programId,
                ],
            ]],
        ])->andReturn($this->remoteResponse([['status' => 'VALID', 'program_id' => $record->programId, 'program_name' => 'Тестова програма']]));
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
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
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
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $api->shouldNotReceive('qualify');
        $api->shouldNotReceive('process');
        $this->instance(ServiceRequestApi::class, $api);

        $this->expectExceptionMessage(__('referrals.messages.action_unavailable'));
        app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, $this->person->uuid);
    }

    public function test_terminal_remote_referral_is_rejected_before_any_mutation(): void
    {
        $record = $this->createActiveReferral();
        app(PatientServiceRequestApi::class)->shouldReceive('getById')->once()->andReturn($this->remoteResponse(
            $this->remoteReferral($record, ['status' => 'recalled'])
        ));
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
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
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $api->shouldNotReceive('qualify', 'process');
        $this->instance(ServiceRequestApi::class, $api);

        $this->expectException(InvalidArgumentException::class);
        app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, $this->person->uuid);
    }

    public function test_local_patient_mismatch_does_not_even_fetch_remote_referral(): void
    {
        $record = $this->createActiveReferral();
        app(PatientServiceRequestApi::class)->shouldNotReceive('getById');

        $this->expectException(InvalidArgumentException::class);
        app(ReferralRequestLifecycleService::class)->takeIntoWork($record->uuid, $this->employee, (string) Str::uuid());
    }

    private function createActiveReferral(): ServiceRequestRequest
    {
        return ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'employee_id' => $this->employee->id,
            'person_id' => $this->person->id, 'status' => 'active', 'request_number' => '0000-1111-2222-3333',
            'service_id' => '59300-00', 'quantity' => 1, 'program_id' => (string) Str::uuid(),
        ]);
    }

    public function test_encounter_selection_prepares_once_and_repeated_selection_does_not_use_again(): void
    {
        $this->actingAs($this->user);
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $api->shouldReceive('qualify')->once()->andReturn($this->remoteResponse([['status' => 'VALID', 'program_id' => $record->programId, 'program_name' => 'Тестова програма']]));
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
        $method = new ReflectionMethod($component, 'resolveAllReferrals');
        $method->invokeArgs($component, [&$validated, true]);
        $this->assertSame($record->uuid, $validated['encounter']['referralNumber']);
    }

    public function testEncounterSelectionWithoutUsePermissionShowsFeedbackBeforeSideEffects(): void
    {
        $this->user->revokePermissionTo('service_request:use');
        $this->actingAs($this->user);
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $component->confirmedElectronicReferralUuid = $record->uuid;
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');
        app(PatientServiceRequestApi::class)->shouldNotReceive('getById');

        $this->assertFalse($component->selectElectronicReferral($record->uuid, $lifecycle));
        $this->assertSame(__('service-requests.policy.use'), Session::get('error'));

        $this->assertSame($record->uuid, $component->confirmedElectronicReferralUuid);
        $this->assertSame('active', $record->fresh()->status);
    }

    public function testRedeemModalAfterSignedEncounterCompletesWithoutRepeatingQualifyOrUse(): void
    {
        $this->actingAs($this->user);
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $encounter = $this->finishedEncounterForReferral($record);
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $api->shouldReceive('qualify')->once()->ordered()->with($record->uuid, [
            'programs' => [[
                'identifier' => [
                    'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'medical_program']]],
                    'value' => $record->programId,
                ],
            ]],
        ])->andReturn($this->remoteResponse([['status' => 'VALID', 'program_id' => $record->programId, 'program_name' => 'Тестова програма']]));
        $api->shouldReceive('process')->once()->ordered()->andReturn($this->remoteResponse(['status' => 'active']));
        $api->shouldReceive('complete')->once()->ordered()->with($record->uuid, [
            'completed_with' => [
                'identifier' => [
                    'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']]],
                    'value' => $encounter->uuid,
                ],
            ],
        ])->andReturn($this->remoteResponse(['status' => 'completed']));
        $this->instance(ServiceRequestApi::class, $api);
        $lifecycle = app(ReferralRequestLifecycleService::class);

        $component->selectElectronicReferral($record->uuid, $lifecycle);
        $this->assertSame($record->uuid, $component->confirmedElectronicReferralUuid);
        $this->assertSame('in_progress', $record->fresh()->status);

        // The async submission has been accepted; completion now runs on the encounter view.
        Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $this->legalEntity, 'person' => $this->person, 'encounter' => $encounter,
        ])->call('openReferralRedeemModal')->call('redeemReferral')->assertSet('showReferralRedeemModal', false)
            ->assertSee(__('encounters.messages.referral_redeemed'));

        $this->assertSame('completed', $record->fresh()->status);
        $this->assertNull(session('error'));
    }

    public function testRedeemModalCannotPrepareAnUnconfirmedReferralAfterSigning(): void
    {
        $this->actingAs($this->user);
        $record = $this->createActiveReferral();
        $encounter = $this->finishedEncounterForReferral($record);
        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $api->shouldNotReceive('qualify', 'process', 'complete');
        $this->instance(ServiceRequestApi::class, $api);
        app(PatientServiceRequestApi::class)->shouldNotReceive('getById');

        Livewire::test(ClinicalEncounterView::class, [
            'legalEntity' => $this->legalEntity, 'person' => $this->person, 'encounter' => $encounter,
        ])->call('openReferralRedeemModal')->assertSet('showReferralRedeemModal', false)
            ->assertSee(__('referrals.messages.action_unavailable'));
        $this->assertSame('active', $record->fresh()->status);
    }

    public function test_failed_selection_clears_previous_confirmation_and_shows_a_field_error(): void
    {
        $this->actingAs($this->user);
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $component->confirmedElectronicReferralUuid = (string) Str::uuid();
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('takeIntoWork')->once()->andThrow(new RuntimeException('Not allowed'));

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
        $method = new ReflectionMethod($component, 'resolveAllReferrals');

        $this->expectException(ValidationException::class);
        $method->invokeArgs($component, [&$validated, true]);
    }

    public function testPackageWithoutElectronicReferralKeepsItsUnrelatedClinicalFields(): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $method = new ReflectionMethod($component, 'resolveAllReferrals');

        foreach ([[], ['referralType' => 'paper', 'referralNumber' => 'Paper referral']] as $referral) {
            $validated = [
                'encounter' => $referral + ['periodDate' => '09.11.2026'],
                'conditions' => [['code' => 'G44']],
                'procedures' => [['referralType' => 'paper', 'basedOnIdentifier' => 'Paper referral']],
            ];
            $expected = $validated;
            $method->invokeArgs($component, [&$validated, true]);

            $this->assertSame($expected, $validated);
        }
        app(PatientServiceRequestApi::class)->shouldNotHaveReceived('getById');
    }

    public function testProcedureReferralStillResolvesThroughTheSharedPackageLogic(): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $validated = ['encounter' => [], 'procedures' => [[
            'referralType' => 'electronic', 'basedOnIdentifier' => $record->requestNumber,
        ]]];

        (new ReflectionMethod($component, 'resolveAllReferrals'))->invokeArgs($component, [&$validated, true]);

        $this->assertSame($record->uuid, $validated['procedures'][0]['basedOnIdentifier']);
        $this->assertSame([], $validated['encounter']);
        app(PatientServiceRequestApi::class)->shouldNotHaveReceived('getById');
    }

    public function test_local_referral_list_includes_in_progress_but_excludes_terminal_statuses(): void
    {
        $record = $this->createActiveReferral();
        $record->update(['status' => 'in_progress']);
        $terminal = $this->createActiveReferral();
        $terminal->update(['status' => 'completed']);
        $component = $this->encounterCreateComponent($record);

        (new ReflectionMethod($component, 'loadAvailableReferrals'))->invoke($component);

        $this->assertSame([$record->uuid], array_column($component->availableReferrals, 'id'));
    }

    public function testPastedNumberSearchesEhealthAndPreparesTheOnlyMatch(): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $resource = $this->fullReferralForNumber($record->uuid);
        $api = app(PatientServiceRequestApi::class);
        $api->shouldReceive('searchForServiceRequestsByParams')->once()->with([
            'requisition' => '0000-1111-2222-3333', 'status' => 'active', 'page' => 1, 'page_size' => 100,
        ])->andReturn($this->remoteResponse([$resource]));
        $api->shouldReceive('getById')->once()->with($this->person->uuid, $record->uuid)
            ->andReturn($this->remoteResponse($resource));
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('takeIntoWork')->once()->with($record->uuid, Mockery::type(Employee::class), $this->person->uuid, Mockery::type('array'))
            ->andReturn(['status' => 'active']);

        $this->assertTrue($component->selectElectronicReferralByNumber(' 0000111122223333 ', $lifecycle));
        $this->assertSame('0000-1111-2222-3333', $component->form->encounter['referralNumber']);
        $this->assertSame($record->uuid, $component->confirmedElectronicReferralUuid);
        $this->assertSame('Тестовий А. І. 0 повних років', $component->availableReferrals[0]['identity']);
        $validated = ['encounter' => ['referralType' => 'electronic', 'referralNumber' => '0000-1111-2222-3333']];
        (new ReflectionMethod($component, 'resolveAllReferrals'))->invokeArgs($component, [&$validated, true]);
        $this->assertSame($record->uuid, $validated['encounter']['referralNumber']);
    }

    public function testOneNumberWithSeveralReferralsRequiresAnExplicitServiceChoiceAcrossPages(): void
    {
        $first = $this->createActiveReferral();
        $second = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($first);
        $api = app(PatientServiceRequestApi::class);
        $pageOne = $this->remoteResponse([$this->fullReferralForNumber($first->uuid)]);
        $pageOne->shouldReceive('isNotLast')->andReturnTrue();
        $api->shouldReceive('searchForServiceRequestsByParams')->once()->with(Mockery::on(fn ($query) => $query['page'] === 1))
            ->andReturn($pageOne);
        $api->shouldReceive('searchForServiceRequestsByParams')->once()->with(Mockery::on(fn ($query) => $query['page'] === 2))
            ->andReturn($this->remoteResponse([$this->fullReferralForNumber($second->uuid)]));
        foreach ([$first, $second] as $record) {
            $api->shouldReceive('getById')->once()->with($this->person->uuid, $record->uuid)
                ->andReturn($this->remoteResponse($this->fullReferralForNumber($record->uuid)));
        }
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');

        $this->assertFalse($component->selectElectronicReferralByNumber('0000-1111-2222-3333', $lifecycle));
        $this->assertCount(2, $component->availableReferrals);
        $this->assertNull($component->confirmedElectronicReferralUuid);
        $this->assertSame(__('encounters.messages.referral_number_multiple'), Session::get('info'));
    }

    public function testReferralFromAnotherPatientIsNeverImportedOrPrepared(): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $foreignUuid = (string) Str::uuid();
        $resource = $this->fullReferralForNumber($foreignUuid);
        $resource['subject']['identifier']['value'] = (string) Str::uuid();
        app(PatientServiceRequestApi::class)->shouldReceive('searchForServiceRequestsByParams')->once()
            ->andReturn($this->remoteResponse([$resource]));
        app(PatientServiceRequestApi::class)->shouldNotReceive('getById');
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');

        $this->assertFalse($component->selectElectronicReferralByNumber('0000-1111-2222-3333', $lifecycle));
        $this->assertSame([], $component->availableReferrals);
        $this->assertFalse(ServiceRequestRequest::whereUuid($foreignUuid)->exists());
        $this->assertTrue($component->getErrorBag()->has('form.encounter.referralNumber'));
    }

    public function testManualNumberDeniedBeforeSearchOrDatabaseChanges(): void
    {
        $this->user->revokePermissionTo('service_request:use');
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        app(PatientServiceRequestApi::class)->shouldNotReceive('searchForServiceRequestsByParams', 'getById');
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');

        $this->assertFalse($component->selectElectronicReferralByNumber('0000-1111-2222-3333', $lifecycle));
        $this->assertSame(__('service-requests.policy.use'), Session::get('error'));
        $this->assertNull($component->confirmedElectronicReferralUuid);
    }

    public function testAcceptedUseWaitsForConfirmationWithoutRepeatingTheMutation(): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldReceive('takeIntoWork')->once()->andReturn(['submissionPending' => true]);
        $lifecycle->shouldReceive('checkCompletion')->once()->with(Mockery::type(ServiceRequestRequest::class), 'service_request:use')
            ->andReturn(['submissionPending' => true]);

        $this->assertSame('pending', $component->selectElectronicReferral($record->uuid, $lifecycle));
        $this->assertSame($record->uuid, $component->pendingElectronicReferralUuid);
        $this->assertNull($component->confirmedElectronicReferralUuid);
        $this->assertFalse($component->getErrorBag()->has('form.encounter.referralNumber'));
        $this->assertSame(__('encounters.messages.referral_use_pending'), Session::get('info'));
        $this->assertSame('pending', $component->checkElectronicReferralPreparation($lifecycle));

        $record->update(['status' => 'in_progress']);
        MedicalEventsRepository::serviceRequest()->applyExecutionResult($record, [
            'used_by_legal_entity' => ['identifier' => ['value' => $this->legalEntity->uuid]],
        ], $record->programId);
        $record = MedicalEventsRepository::serviceRequest()->importResource(Arr::toCamelCase($this->remoteReferral($record)), $this->person);
        $lifecycle->shouldReceive('checkCompletion')->once()->with(Mockery::type(ServiceRequestRequest::class), 'service_request:use')
            ->andReturn(['submissionPending' => false]);
        $this->assertTrue($component->checkElectronicReferralPreparation($lifecycle), (string) Session::get('error'));
        $this->assertSame($record->uuid, $component->confirmedElectronicReferralUuid);
        $this->assertNull($component->pendingElectronicReferralUuid);
    }

    public function testRejectedAsyncUseLeavesAnActionableFieldErrorAndNoConfirmedSelection(): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $component->pendingElectronicReferralUuid = $record->uuid;
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');
        $lifecycle->shouldReceive('checkCompletion')->once()->andThrow(new RuntimeException('Synthetic rejected use'));

        $this->assertFalse($component->checkElectronicReferralPreparation($lifecycle));
        $this->assertNull($component->confirmedElectronicReferralUuid);
        $this->assertNull($component->pendingElectronicReferralUuid);
        $this->assertSame('Synthetic rejected use', $component->getErrorBag()->first('form.encounter.referralNumber'));
        $this->assertStringContainsString('Synthetic rejected use', Session::get('error'));
    }

    public function testRetryingTheNumberResumesAnExistingUseWithoutImportingOverItsPendingChanges(): void
    {
        $record = $this->createActiveReferral();
        $resource = $this->fullReferralForNumber($record->uuid);
        $repository = MedicalEventsRepository::serviceRequest();
        $record = $repository->importResource((new ServiceRequestMapper())->normalize($resource), $this->person->id);
        $link = $repository->beginSubmission($record, ['programProcessingStatus' => 'in_progress'], 'service_request:use');
        $component = $this->encounterCreateComponent($record);
        $api = app(PatientServiceRequestApi::class);
        $api->shouldReceive('searchForServiceRequestsByParams')->once()->andReturn($this->remoteResponse([$resource]));
        $api->shouldReceive('getById')->once()->andReturn($this->remoteResponse($resource));
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');

        $this->assertSame('pending', $component->selectElectronicReferralByNumber('0000-1111-2222-3333', $lifecycle));
        $this->assertSame(JobStatus::PENDING->value, $link->fresh()->status);
        $this->assertSame($record->uuid, $component->pendingElectronicReferralUuid);
        $this->assertNull($component->confirmedElectronicReferralUuid);
    }

    public function testUuidCannotBeMistakenForTheHumanReferralNumber(): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        app(PatientServiceRequestApi::class)->shouldNotReceive('searchForServiceRequestsByParams', 'getById');
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');

        $this->assertFalse($component->selectElectronicReferralByNumber($record->uuid, $lifecycle));
        $this->assertSame(__('encounters.messages.referral_number_invalid'), $component->getErrorBag()->first('form.encounter.referralNumber'));
    }

    public function testDropdownKeepsTheNumberSeparateFromUuidAndDisplaysServiceContext(): void
    {
        $record = $this->createActiveReferral();
        $resource = $this->fullReferralForNumber($record->uuid);
        MedicalEventsRepository::serviceRequest()->importResource((new ServiceRequestMapper())->normalize($resource), $this->person->id);
        $component = $this->encounterCreateComponent($record);
        $component->loadAvailableReferrals();

        $this->assertSame('0000-1111-2222-3333', $component->availableReferrals[0]['requisition']);
        $this->assertSame($record->uuid, $component->availableReferrals[0]['id']);
        $this->assertNotEmpty($component->availableReferrals[0]['serviceName']);
        $this->assertNotEmpty($component->availableReferrals[0]['priority']);
        $this->assertNotEmpty($component->availableReferrals[0]['programName']);
    }

    private function fullReferralForNumber(string $uuid): array
    {
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['id'] = $uuid;
        $resource['requisition'] = '0000-1111-2222-3333';
        $resource['subject']['identifier']['value'] = $this->person->uuid;
        $resource['identity'] = ['last_name' => 'Тестовий', 'first_name' => 'Андрій', 'second_name' => 'Іванович', 'age' => 0];

        return $resource;
    }

    private function encounterCreateComponent(ServiceRequestRequest $record): EncounterPackageCreate
    {
        $component = new EncounterPackageCreate();
        $component->form = new EncounterForm($component, 'form');
        $component->personId = $this->person->id;
        $component->patientUuid = $this->person->uuid;
        $component->availableReferrals = [['id' => $record->uuid, 'requisition' => '0000-1111-2222-3333']];

        return $component;
    }

    private function remoteResponse(array $data): EHealthResponse
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn($data);
        $response->shouldReceive('getStatusCode')->andReturn(200);
        $response->shouldReceive('validate')->andReturn($data);
        $response->shouldReceive('isNotLast')->andReturnFalse()->byDefault();

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

        $mockApi = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $mockApi->shouldReceive('qualify')
            ->once()
            ->andThrow(new EHealthValidationException([
                'error' => ['message' => 'program not allowed'],
            ]));
        $mockApi->shouldReceive('process')->never();
        $this->app->instance(ServiceRequestApi::class, $mockApi);

        $service = app(ReferralRequestLifecycleService::class);

        $this->expectException(RuntimeException::class);
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

        $mockApi = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $qualifyResponse = Mockery::mock(EHealthResponse::class);
        $qualifyResponse->shouldReceive('validate')->andReturn([
            ['status' => 'INVALID', 'program_id' => $programId, 'program_name' => 'Тестова програма', 'rejection_reason' => 'limit exceeded'],
        ]);
        $mockApi->shouldReceive('qualify')
            ->once()
            ->andReturn($qualifyResponse);
        $mockApi->shouldReceive('process')->never();
        $this->app->instance(ServiceRequestApi::class, $mockApi);

        $service = app(ReferralRequestLifecycleService::class);

        $this->expectException(RuntimeException::class);
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
        $recallResponse->shouldReceive('getStatusCode')->andReturn(200);
        $recallResponse->shouldReceive('validate')->andReturn(['status' => 'recalled']);

        $mockPatientApi = Mockery::mock(PatientServiceRequestApi::class)->makePartial();
        $detail = json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR);
        $detail['id'] = $referralUuid;
        unset($detail['subject']);
        $detailResponse = Mockery::mock(EHealthResponse::class);
        $detailResponse->shouldReceive('validate')->once()->andReturn($detail);
        $mockPatientApi->shouldReceive('getById')->once()->with($this->person->uuid, $referralUuid)->andReturn($detailResponse);
        $mockPatientApi->shouldReceive('recall')
            ->once()
            ->with($this->person->uuid, $referralUuid, Mockery::on(static function (array $payload): bool {
                return ($payload['signed_data'] ?? '') === 'signed-payload' && !isset($payload['explanatory_letter']);
            }))
            ->andReturn($recallResponse);
        $this->app->instance(PatientServiceRequestApi::class, $mockPatientApi);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->recallReferral($this->person->uuid, $referralUuid, [
            'signed_data' => 'signed-payload',
            'explanatory_letter' => 'Пацієнт більше не потребує послуги',
        ]);

        $this->assertSame('recalled', $result['status']);
        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $referralUuid,
            'status' => 'recalled',
        ]);
    }

    public function test_complete_referral_builds_completed_with_for_encounter(): void
    {
        $record = $this->createActiveReferral();
        $record->update(['status' => 'in_progress']);
        $referralUuid = $record->uuid;
        MedicalEventsRepository::serviceRequest()->applyExecutionResult($record, [
            'used_by_legal_entity' => ['identifier' => ['value' => $this->legalEntity->uuid]],
        ], $record->programId);
        $encounterUuid = $this->finishedEncounterForReferral($record)->uuid;

        $completeResponse = $this->remoteResponse(['status' => 'completed']);

        $mockApi = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $mockApi->shouldReceive('complete')
            ->once()
            ->with($referralUuid, Mockery::on(static function (array $payload) use ($encounterUuid): bool {
                return data_get($payload, 'completed_with.identifier.type.coding.0.code') === 'encounter'
                    && data_get($payload, 'completed_with.identifier.value') === $encounterUuid;
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

        $codingId = Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = CodeableConcept::create()->id;
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.referral_complete_emz_mismatch'));

        app(ReferralRequestLifecycleService::class)->completeReferral($referralUuid, $encounterUuid, 'encounter');
    }

    public function test_referral_index_complete_requires_linked_encounter(): void
    {
        $this->actingAs($this->user);

        $record = $this->createActiveReferral();
        MedicalEventsRepository::serviceRequest()->applyExecutionResult($record, [
            'used_by_legal_entity' => ['identifier' => ['value' => $this->legalEntity->uuid]],
        ], $record->programId);
        $referralUuid = $record->uuid;
        $encounterUuid = $this->finishedEncounterForReferral($record)->uuid;

        $mockLifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $mockLifecycle->shouldReceive('completeReferral')
            ->once()
            ->with($referralUuid, $encounterUuid, 'encounter', ['acknowledge_remaining_quantity' => false])
            ->andReturn(['status' => 'completed']);
        $this->instance(ReferralRequestLifecycleService::class, $mockLifecycle);

        Livewire::test(ReferralIndexFixture::class, ['legalEntity' => $this->legalEntity])
            ->call('seedResults', [[
                'id' => $referralUuid,
                'status' => 'in_progress',
                'used_by_legal_entity' => ['identifier' => ['value' => $this->legalEntity->uuid]],
                'subject' => [
                    'identifier' => ['value' => $this->person->uuid],
                ],
            ]])
            ->call('openCompleteModal', $referralUuid)
            ->assertSet('showCompleteModal', true)
            ->assertSet('selectedEmzType', 'encounter')
            ->set('selectedEmzUuid', $encounterUuid)
            ->call('confirmComplete')
            ->assertSet('searchResults.0.status', 'completed')
            ->assertSee(__('referrals.messages.complete_success'))
            ->assertNotDispatched('notify');
    }

    public function test_search_results_translate_diagnostic_procedure_category(): void
    {
        $this->actingAs($this->user);

        Livewire::test(ReferralIndexFixture::class, ['legalEntity' => $this->legalEntity])
            ->set('hasSearched', true)
            ->call('seedResults', [[
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

        Livewire::test(ReferralIndexFixture::class, ['legalEntity' => $this->legalEntity])
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

    private function finishedEncounterForReferral(ServiceRequestRequest $record): Encounter
    {
        $class = Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes']);
        $type = CodeableConcept::create();
        $episode = Identifier::create(['value' => (string) Str::uuid()]);
        $incoming = Identifier::create(['value' => $record->uuid]);
        Episode::create(['uuid' => $episode->value, 'person_id' => $this->person->id, 'status' => 'active', 'name' => 'Synthetic episode', 'managing_organization_id' => Identifier::create(['value' => $this->legalEntity->uuid])->id]);

        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id,
            'status' => 'finished', 'episode_id' => $episode->id,
            'class_id' => $class->id, 'type_id' => $type->id,
            'incoming_referral_id' => $incoming->id, 'ehealth_inserted_at' => now(),
        ]);
        $encounter->actionReferences()->attach(Identifier::create(['value' => $record->serviceId])->id);

        return $encounter;
    }

    private function remoteReferral(ServiceRequestRequest $record, array $overrides = []): array
    {
        $record = $record->fresh();
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/ServiceRequest/resource.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['id'] = $record->uuid;
        $resource['code']['identifier']['value'] = $record->serviceId;
        $resource['code']['identifier']['type']['coding'][0]['code'] = 'service';
        $resource['subject'] = ['identifier' => ['value' => $this->person->uuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'patient']]]]];
        $resource['program']['identifier']['value'] = $record->programId;
        $resource['requesterEmployee']['identifier']['value'] = $this->employee->uuid;
        $resource['requesterLegalEntity']['identifier']['value'] = $this->legalEntity->uuid;
        $resource['usedByEmployee'] = ['displayValue' => ''];
        $resource['usedByLegalEntity'] = ['displayValue' => ''];
        foreach (['usedByEmployee' => 'employee', 'usedByLegalEntity' => 'legal_entity'] as $field => $type) {
            if ($record->{$field} !== null) {
                $resource[$field]['identifier'] = ['value' => $record->{$field}->value, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]]];
            }
        }
        if ($record->status === 'in_progress') {
            $resource['status'] = 'active';
        }

        return array_replace(Arr::toSnakeCase($resource), ['program_processing_status' => $record->programProcessingStatus ?? 'new'], $overrides);
    }

    public static function incomingReferralValidationPhases(): array
    {
        return ['selection' => [false], 'asynchronous confirmation' => [true]];
    }

    #[DataProvider('incomingReferralValidationPhases')]
    public function test_incoming_referral_validation_failure_preserves_details_and_clears_confirmation(bool $checking): void
    {
        $record = $this->createActiveReferral();
        $component = $this->encounterCreateComponent($record);
        $exception = new EHealthValidationException(['error' => [
            'type' => 'validation_failed', 'message' => 'Validation failed',
            'invalid' => [['entry' => 'used_by_division.identifier.value', 'rules' => [['description' => 'Synthetic unavailable division']]]],
        ]]);
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        if ($checking) {
            $component->pendingElectronicReferralUuid = $record->uuid;
            $lifecycle->shouldNotReceive('takeIntoWork');
            $lifecycle->shouldReceive('checkCompletion')->once()->andThrow($exception);
        } else {
            $lifecycle->shouldReceive('takeIntoWork')->once()->andThrow($exception);
        }
        Log::spy();

        $result = $checking
            ? $component->checkElectronicReferralPreparation($lifecycle)
            : $component->selectElectronicReferral($record->uuid, $lifecycle);

        $this->assertFalse($result);
        $this->assertNull($component->confirmedElectronicReferralUuid);
        $this->assertNull($component->selectedReferralUuid);
        $this->assertNull($component->pendingElectronicReferralUuid);
        $this->assertSame($exception->getFormattedMessage(), $component->getErrorBag()->first('form.encounter.referralNumber'));
        $this->assertSame(__('encounters.messages.referral_use_failed', ['message' => $exception->getFormattedMessage()]), Session::get('error'));
        $this->assertStringContainsString('Synthetic unavailable division', Session::get('error'));
        Log::shouldHaveReceived('error')->once()->with('eHealth API Validation Error Detail', [
            'message' => $exception->getMessage(), 'details' => $exception->getDetails(),
        ]);
    }
}
