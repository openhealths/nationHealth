<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Classes\eHealth\Api\Patient\DeviceDispense as DeviceDispenseApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\SchemaResponseContract;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Livewire\DeviceDispense\DeviceDispenses;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class DeviceDispensePatientViewTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;
    private Person $person;
    private DeviceDispenseApi $api;
    private bool $canRead = true;
    private bool $denyRecordView = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['pagination.per_page' => 10]);
        $this->legalEntity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->instance('legalEntity', $this->legalEntity);
        $this->person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Test',
            'last_name' => 'Doctor',
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
            'tax_id' => '1234567890',
        ]);
        $this->actingAs(User::create([
            'email' => Str::random(12) . '@example.com',
            'password' => 'unused',
            'party_id' => $party->id,
        ]));
        Gate::before(fn (User $user, string $ability, array $arguments): bool => $ability === 'create'
            || ($ability === 'view' && $this->denyRecordView && ($arguments[0] ?? null) instanceof DeviceDispense)
            ? false : $this->canRead);
        $this->api = Mockery::mock(DeviceDispenseApi::class);
        $this->instance(DeviceDispenseApi::class, $this->api);
    }

    public function testRemoteDispenseIsValidatedStoredAndOpenedForThePatient(): void
    {
        $uuid = (string) Str::uuid();
        $this->api->shouldReceive('getById')->once()->with($this->person->uuid, $uuid)
            ->andReturn($this->response($uuid));

        $component = $this->patientComponent()->call('openDeviceDispenseView', $uuid);
        $dispense = DeviceDispense::whereUuid($uuid)->firstOrFail();
        $component->assertRedirect(route('persons.device-dispenses.view', [
            $this->legalEntity,
            'person' => $this->person->id,
            'deviceDispense' => $dispense->id,
        ]));
        $this->assertSame($this->person->id, $dispense->personId);
        $this->assertSame('2026-10-20 09:00:00', $dispense->getRawOriginal('ehealth_inserted_at'));
        $this->assertEquals(5, $dispense->details->first()->quantity->value);
    }

    public function testExistingLocalDispenseDoesNotRequireARemoteFetch(): void
    {
        $dispense = DeviceDispense::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'completed',
        ]);
        $this->api->shouldNotReceive('getById');
        $this->patientComponent()->call('openDeviceDispenseView', $dispense->uuid)
            ->assertRedirect(route('persons.device-dispenses.view', [
                $this->legalEntity,
                'person' => $this->person->id,
                'deviceDispense' => $dispense->id,
            ]));
    }

    public function testAnotherPatientsDispenseCannotBeReassignedOrOpened(): void
    {
        $otherPerson = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $dispense = DeviceDispense::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $otherPerson->id,
            'status' => 'completed',
        ]);
        $this->api->shouldNotReceive('getById');
        $this->expectException(ModelNotFoundException::class);
        try {
            $this->patientComponent()->call('openDeviceDispenseView', $dispense->uuid);
        } finally {
            $this->assertSame($otherPerson->id, $dispense->fresh()->personId);
        }
    }

    public function testInvalidUuidIsRejectedBeforeFetching(): void
    {
        $this->api->shouldNotReceive('getById');
        $this->patientComponent()->call('openDeviceDispenseView', 'invalid')->assertHasErrors(['uuid']);
    }

    public function testActionChecksPermissionBeforeFetching(): void
    {
        $component = $this->patientComponent();
        $this->canRead = false;
        $this->api->shouldNotReceive('getById');
        $uuid = (string) Str::uuid();

        $component->call('openDeviceDispenseView', $uuid)
            ->assertNoRedirect();

        $component->instance()->openDeviceDispenseView($uuid);
        $this->assertSame(__('device-dispenses.policy.view'), Session::get('error'));
    }

    public function testRecordPolicyCanDenyOpeningAfterRegistryPermissionWasGranted(): void
    {
        $dispense = DeviceDispense::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'completed',
        ]);
        $component = $this->patientComponent();
        $this->denyRecordView = true;
        $this->api->shouldNotReceive('getById');

        $component->call('openDeviceDispenseView', $dispense->uuid)
            ->assertNoRedirect();

        $component->instance()->openDeviceDispenseView($dispense->uuid);
        $this->assertSame(__('device-dispenses.policy.view'), Session::get('error'));
    }

    public function testInvalidRemoteRecordIsNotPersistedOrOpened(): void
    {
        $uuid = (string) Str::uuid();
        $this->api->shouldReceive('getById')->once()->andReturn($this->response($uuid, 'invalid-status'));
        $this->patientComponent()->call('openDeviceDispenseView', $uuid)->assertNoRedirect();
        $this->assertDatabaseMissing('device_dispenses', ['uuid' => $uuid]);
    }

    public function testConnectionErrorWhileOpeningRemoteDispenseFlashesErrorAndDoesNotRedirect(): void
    {
        $uuid = (string) Str::uuid();
        $this->api->shouldReceive('getById')->twice()->with($this->person->uuid, $uuid)
            ->andThrow(new EHealthConnectionException('Timeout'));

        $component = $this->patientComponent();
        $component->call('openDeviceDispenseView', $uuid)
            ->assertNoRedirect();

        $component->instance()->openDeviceDispenseView($uuid);
        $this->assertSame(__('messages.connection_exception'), Session::get('error'));
        $this->assertDatabaseMissing('device_dispenses', ['uuid' => $uuid]);
    }

    private function patientComponent(): Testable
    {
        Livewire::component('device-dispenses-patient-view-test', DeviceDispensesForPatientViewTest::class);

        return Livewire::test('device-dispenses-patient-view-test', [
            'legalEntity' => $this->legalEntity,
            'personId' => $this->person->id,
        ]);
    }

    private function response(string $uuid, string $status = 'completed'): EHealthResponse
    {
        $data = NativeClinicalFixtures::deviceDispense($uuid, $this->person->uuid);
        if ($status !== 'completed') {
            $data['primary_source'] = 'invalid';
        }
        $api = new class extends DeviceDispenseApi
        {
            public function mapped(EHealthResponse $response): array
            {
                return $this->replaceEHealthPropNames(Arr::toSnakeCase(SchemaResponseContract::validate($response, 'GET /api/patients/{patient_id}/device_dispenses/{device_dispense_id}')));
            }
        };

        return new EHealthResponse(new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR)), fn (EHealthResponse $response): array => $api->mapped($response));
    }
}

class DeviceDispensesForPatientViewTest extends DeviceDispenses
{
    protected function initializeComponent(): void
    {
        $this->dictionaries = ['device_definition_classification_type' => []];
    }
}
