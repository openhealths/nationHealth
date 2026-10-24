<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\CarePlanRepository;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CarePlanSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_care_plan_and_activities_can_be_synced_and_mapped_to_structured_sql(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $plan = $this->fixture('plan');
        $plan['author']['identifier']['value'] = $employee->uuid;
        $activity = $this->fixture('activity');
        $api = $this->bindRemote($plan, [$activity], $person->uuid);

        $api->synchronizeResources([['id' => $plan['id']]], $person->id, $employee->legalEntityId);

        $stored = \App\Models\CarePlan::whereUuid($plan['id'])->firstOrFail();
        $storedActivity = \App\Models\CarePlanActivity::whereUuid($activity['id'])->firstOrFail();
        $this->assertSame($employee->id, $stored->authorId);
        $this->assertNotNull($stored->categoryId);
        $this->assertSame($plan['category'], app(CarePlanRepository::class)->getResource($stored->id)['category']);
        $this->assertSame($activity['detail']['kind'], $storedActivity->kind);
        $this->assertSame($activity['detail']['reasonCode'], app(CarePlanActivityRepository::class)->getResource($storedActivity->id)['detail']['reasonCode']);
        $this->assertNull($storedActivity->getRawOriginal('quantity'));
    }

    public function test_sync_preserves_unknown_remote_authors_without_attributing_them_to_the_caller(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $plan = $this->fixture('plan');
        $api = $this->bindRemote($plan, [], $person->uuid);

        $api->synchronizeResources([['id' => $plan['id']]], $person->id, $employee->legalEntityId);

        $stored = \App\Models\CarePlan::whereUuid($plan['id'])->firstOrFail();
        $this->assertNull($stored->authorId);
        $this->assertEquals($plan['author'], app(CarePlanRepository::class)->getResource($stored->id)['author']);
    }

    public function test_sync_does_not_substitute_the_signed_in_employee_for_a_remote_author(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $this->actingAs(User::findOrFail($employee->userId));
        $plan = $this->fixture('plan');
        $api = $this->bindRemote($plan, [], $person->uuid);

        $api->synchronizeResources([['id' => $plan['id']]], $person->id, $employee->legalEntityId);

        $stored = \App\Models\CarePlan::whereUuid($plan['id'])->firstOrFail();
        $this->assertNull($stored->authorId);
        $this->assertSame($plan['author']['identifier']['value'], app(CarePlanRepository::class)->getResource($stored->id)['author']['identifier']['value']);
    }

    private function bindRemote(array $plan, array $activities, string $patientUuid): CarePlanApi
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($plan);
        $api = Mockery::mock(CarePlanApi::class)->makePartial();
        $api->shouldReceive('getDetails')->once()->with($patientUuid, $plan['id'])->andReturn($response);
        $activityApi = Mockery::mock(ActivityApi::class);
        $activityApi->shouldReceive('fetchAllResources')->once()->with($patientUuid, $plan['id'])->andReturn($activities);
        $this->instance(ActivityApi::class, $activityApi);

        return $api;
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Person, 1: \App\Models\Employee\Employee}
     */
    private function makeSyncContext(): array
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = \App\Models\LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'gender' => 'MALE',
            'birth_date' => '1990-01-01',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
            'legal_entity_id' => $legalEntity->id,
        ]);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Gregory',
            'last_name' => 'Doctor',
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'sync-'.Str::random(8).'@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = \App\Models\Employee\Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. House',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        return [$person, $employee];
    }

}
