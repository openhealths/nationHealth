<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\Referral\ReferralIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\Concerns\GrantsMedicalEventAbilities;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsMedicalEventAbilities;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'email' => 'ref_'.\Illuminate\Support\Str::random(6).'@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $this->employee = Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
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
    }

    public function test_it_can_find_referral_by_requisition(): void
    {
        $payload = [
            'data' => [
                ['id' => '00000000-0000-4000-8000-000000000123', 'status' => 'active'],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('getData')->andReturn($payload);

        $mockApi = Mockery::mock(ServiceRequestApi::class);
        $mockApi->shouldReceive('searchForServiceRequestsByParams')
            ->once()
            ->with(['requisition' => '1234-5678-9012-3456'])
            ->andReturn($mockResponse);
        $this->app->instance(ServiceRequestApi::class, $mockApi);

        $response = app(ServiceRequestApi::class)
            ->searchForServiceRequestsByParams(['requisition' => '1234-5678-9012-3456'])
            ->getData();

        $this->assertEquals('00000000-0000-4000-8000-000000000123', $response['data'][0]['id']);
    }

    public function test_it_can_complete_referral(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000123';
        $encounterUuid = '00000000-0000-4000-8000-000000000456';

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('getData')->andReturn([
            'id' => $uuid,
            'status' => 'completed',
        ]);

        $person = \App\Models\Person\Person::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Complete',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;
        $episodeId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) \Illuminate\Support\Str::uuid()])->id;

        \App\Models\MedicalEvents\Sql\Encounter::create([
            'uuid' => $encounterUuid,
            'person_id' => $person->id,
            'status' => 'finished',
            'episode_id' => $episodeId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);

        $mockApi = Mockery::mock(ServiceRequestApi::class);
        $mockApi->shouldReceive('complete')
            ->once()
            ->with($uuid, Mockery::on(static function (array $sent) use ($encounterUuid): bool {
                return !array_key_exists('status', $sent)
                    && !array_key_exists('injected', $sent)
                    && data_get($sent, 'based_on.0.identifier.value') === $encounterUuid;
            }))
            ->andReturn($mockResponse);
        $this->app->instance(ServiceRequestApi::class, $mockApi);

        $service = new \Tests\Support\ReferralExecutionHarness();
        $result = $service->completeReferral($uuid, $encounterUuid, 'encounter');

        $this->assertEquals('completed', $result['status']);
    }

    public function test_referral_index_component_renders(): void
    {
        $this->actingAs($this->user);

        Livewire::test(\App\Livewire\Referral\ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->assertStatus(200)
            ->assertSee('Знайти направлення');
    }

    public function testIndexSearchRequiresNumberAndShowsOnlyApiResults(): void
    {
        $this->actingAs($this->user);
        $referrals = [['id' => (string) Str::uuid(), 'status' => 'active']];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn(['data' => $referrals]);
        $api = Mockery::mock(PatientServiceRequestApi::class);
        $api->shouldReceive('searchForServiceRequestsByParams')->once()
            ->with(['requisition' => 'ABCD-1234-EFGH-5678'])->andReturn($response);
        $this->instance(PatientServiceRequestApi::class, $api);

        Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->assertSet('hasSearched', false)
            ->assertSet('searchResults', [])
            ->call('search')
            ->assertHasErrors(['requisition' => 'required'])
            ->set('requisition', 'abcd1234efgh5678')
            ->call('search')
            ->assertHasNoErrors()
            ->assertSet('requisition', 'ABCD-1234-EFGH-5678')
            ->assertSet('searchResults', $referrals)
            ->assertSee($referrals[0]['id']);
    }

    public function testFailedSearchClearsOldResults(): void
    {
        $this->actingAs($this->user);
        $api = Mockery::mock(PatientServiceRequestApi::class);
        $api->shouldReceive('searchForServiceRequestsByParams')->once()->andThrow(new RuntimeException('Search failed'));
        $this->instance(PatientServiceRequestApi::class, $api);

        Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('searchResults', [['id' => (string) Str::uuid(), 'status' => 'active']])
            ->set('requisition', 'ABCD-1234-EFGH-5678')
            ->call('search')
            ->assertSet('searchResults', [])
            ->assertSee(__('referrals.messages.search_failed'));
    }

    public function testCancelUsageRequiresNonblankExplanation(): void
    {
        $this->actingAs($this->user);
        [$referral, $person] = $this->createInProgressReferral();
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('cancelUsage');
        $this->instance(ReferralRequestLifecycleService::class, $lifecycle);

        Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('searchResults', [$this->searchRow($referral, $person)])
            ->call('openCancelModal', $referral->uuid)
            ->set('cancelExplanatoryLetter', '   ')
            ->call('confirmCancelUsage')
            ->assertHasErrors(['cancelExplanatoryLetter' => 'required'])
            ->assertSet('showCancelModal', true)
            ->assertSet('searchResults.0.status', 'in_progress')
            ->assertNotDispatched('notify');
    }

    public function testCancelUsageUpdatesDatabaseAfterApiSuccessAndShowsFlashOnce(): void
    {
        $this->actingAs($this->user);
        [$referral, $person] = $this->createInProgressReferral();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn(['status' => 'active']);
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('cancelUsage')->once()->with($referral->uuid, $person->uuid, [
            'explanatory_letter' => 'Пацієнт звернувся до іншого закладу',
        ])->andReturn($response);
        $this->instance(ServiceRequestApi::class, $api);

        Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('hasSearched', true)
            ->set('searchResults', [$this->searchRow($referral, $person)])
            ->call('openCancelModal', $referral->uuid)
            ->set('cancelExplanatoryLetter', '  Пацієнт звернувся до іншого закладу  ')
            ->call('confirmCancelUsage')
            ->assertSet('showCancelModal', false)
            ->assertSet('searchResults.0.status', 'active')
            ->assertSee('Взяти в роботу')
            ->assertDontSee('Погасити направлення')
            ->assertSee(__('referrals.messages.cancel_usage_success'))
            ->assertNotDispatched('notify')
            ->call('$refresh')
            ->assertDontSee(__('referrals.messages.cancel_usage_success'));

        $this->assertSame('active', $referral->refresh()->status);
    }

    public function testFailedCancelUsageKeepsModalAndDatabaseState(): void
    {
        $this->actingAs($this->user);
        [$referral, $person] = $this->createInProgressReferral();
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('cancelUsage')->once()->andThrow(new RuntimeException('API rejected cancellation'));
        $this->instance(ServiceRequestApi::class, $api);

        Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('searchResults', [$this->searchRow($referral, $person)])
            ->call('openCancelModal', $referral->uuid)
            ->set('cancelExplanatoryLetter', 'Причина відміни використання')
            ->call('confirmCancelUsage')
            ->assertSet('showCancelModal', true)
            ->assertSet('searchResults.0.status', 'in_progress')
            ->assertSee(__('referrals.messages.operation_failed'))
            ->assertDontSee(__('referrals.messages.cancel_usage_success'))
            ->assertNotDispatched('notify');

        $this->assertSame('in_progress', $referral->refresh()->status);
    }

    public function testDraftAndTerminalReferralsCannotRunUsageActions(): void
    {
        $this->actingAs($this->user);
        $lifecycle = Mockery::mock(ReferralRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('takeIntoWork');
        $lifecycle->shouldNotReceive('cancelUsage');
        $lifecycle->shouldNotReceive('completeReferral');
        $this->instance(ReferralRequestLifecycleService::class, $lifecycle);

        foreach (['draft', 'completed', 'entered-in-error', 'revoked'] as $status) {
            $referralUuid = (string) Str::uuid();
            Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
                ->set('hasSearched', true)
                ->set('searchResults', [[
                    'id' => $referralUuid,
                    'status' => $status,
                    'program_processing_status' => 'in_progress',
                ]])
                ->assertDontSee('Взяти в роботу')
                ->assertDontSee('Погасити направлення')
                ->assertDontSee('Відмінити використання')
                ->assertDontSee('Видалити заявку')
                ->assertDontSee('Позначити помилковим')
                ->call('process', $referralUuid, (string) Str::uuid())
                ->call('openCancelModal', $referralUuid)
                ->assertSet('showCancelModal', false)
                ->call('openCompleteModal', $referralUuid)
                ->assertSet('showCompleteModal', false);
        }
    }

    public function testCompleteRequiresLinkedEmzAndPreservesStateOnApiFailure(): void
    {
        $this->actingAs($this->user);
        [$referral, $person] = $this->createInProgressReferral();
        $incoming = Identifier::create(['value' => $referral->uuid]);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status' => 'finished',
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id,
            'incoming_referral_id' => $incoming->id,
        ]);
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('complete')->once()->andThrow(new RuntimeException('API rejected completion'));
        $this->instance(ServiceRequestApi::class, $api);

        Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->set('searchResults', [$this->searchRow($referral, $person)])
            ->call('openCompleteModal', $referral->uuid)
            ->assertSet('availableEmzResources.0.uuid', $encounter->uuid)
            ->call('confirmComplete')
            ->assertSee(__('care-plan.referral_complete_emz_required'))
            ->set('selectedEmzUuid', (string) Str::uuid())
            ->call('confirmComplete')
            ->assertSee(__('care-plan.referral_complete_emz_not_linked'))
            ->set('selectedEmzUuid', $encounter->uuid)
            ->call('confirmComplete')
            ->assertSet('showCompleteModal', true)
            ->assertSet('searchResults.0.status', 'in_progress')
            ->assertSee(__('referrals.messages.operation_failed'))
            ->assertNotDispatched('notify');

        $this->assertSame('in_progress', $referral->refresh()->status);
    }

    public function testUsageActionsRequirePermission(): void
    {
        $unauthorizedUser = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'referral-denied-'.Str::random(8).'@example.com',
            'password' => 'unused',
        ]);
        $this->actingAs($unauthorizedUser);
        Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->call('openCancelModal', (string) Str::uuid())->assertForbidden();
        Livewire::test(ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->call('openCompleteModal', (string) Str::uuid())->assertForbidden();
    }

    private function createInProgressReferral(): array
    {
        $person = new Person();
        $person->uuid = (string) Str::uuid();
        $person->birthDate = '1990-01-01';
        $person->gender = 'FEMALE';
        $person->patientSigned = true;
        $person->processDisclosureDataConsent = true;
        $person->save();
        $referral = new ServiceRequestRequest();
        $referral->uuid = (string) Str::uuid();
        $referral->employeeId = $this->employee->id;
        $referral->personId = $person->id;
        $referral->status = 'in_progress';
        $referral->serviceId = (string) Str::uuid();
        $referral->quantity = 1;
        $referral->save();

        return [$referral, $person];
    }

    private function searchRow(ServiceRequestRequest $referral, Person $person): array
    {
        return [
            'id' => $referral->uuid,
            'status' => $referral->status,
            'program_processing_status' => 'in_progress',
            'subject' => ['identifier' => ['value' => $person->uuid]],
        ];
    }
}
