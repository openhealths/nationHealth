<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Classes\eHealth\Api\Patient\DeviceDispense as DeviceDispenseApi;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Livewire\DeviceDispense\DeviceDispenses;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\Dictionary\DictionaryManager;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\Collections\DeviceDefinitionCollection;
use App\Services\Dictionary\Collections\ServiceCollection;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use ReflectionMethod;
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
        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Test', 'last_name' => 'Doctor',
            'birth_date' => '1980-01-01', 'gender' => 'MALE', 'tax_id' => '1234567890',
        ]);
        $this->actingAs(User::create(['email' => Str::random(12).'@example.com', 'password' => 'unused', 'party_id' => $party->id]));
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
        $component->assertRedirect(route('persons.device-dispenses.view', [$this->legalEntity, 'person' => $this->person->id, 'deviceDispense' => $dispense->id]));
        $this->assertSame($this->person->id, $dispense->personId);
        $this->assertSame('2026-10-20 09:00:00', $dispense->getRawOriginal('ehealth_inserted_at'));
        $this->assertEquals(5, $dispense->details->first()->quantity->value);
    }

    public function testExistingLocalDispenseDoesNotRequireARemoteFetch(): void
    {
        $dispense = DeviceDispense::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'completed']);
        $this->api->shouldNotReceive('getById');
        $this->patientComponent()->call('openDeviceDispenseView', $dispense->uuid)
            ->assertRedirect(route('persons.device-dispenses.view', [$this->legalEntity, 'person' => $this->person->id, 'deviceDispense' => $dispense->id]));
    }

    public function testAnotherPatientsDispenseCannotBeReassignedOrOpened(): void
    {
        $otherPerson = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $dispense = DeviceDispense::create(['uuid' => (string) Str::uuid(), 'person_id' => $otherPerson->id, 'status' => 'completed']);
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
        $component->call('openDeviceDispenseView', (string) Str::uuid())
            ->assertNoRedirect()
            ->assertSee(__('device-dispenses.policy.view'));
    }

    public function testRecordPolicyCanDenyOpeningAfterRegistryPermissionWasGranted(): void
    {
        $dispense = DeviceDispense::create(['uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'completed']);
        $component = $this->patientComponent();
        $this->denyRecordView = true;
        $this->api->shouldNotReceive('getById');

        $component->call('openDeviceDispenseView', $dispense->uuid)
            ->assertNoRedirect()
            ->assertSee(__('device-dispenses.policy.view'));
    }

    public function testInvalidRemoteRecordIsNotPersistedOrOpened(): void
    {
        $uuid = (string) Str::uuid();
        $this->api->shouldReceive('getById')->once()->andReturn($this->response($uuid, 'invalid-status'));
        $this->patientComponent()->call('openDeviceDispenseView', $uuid)->assertNoRedirect();
        $this->assertDatabaseMissing('device_dispenses', ['uuid' => $uuid]);
    }

    public function testSearchConnectionErrorIsVisibleInTheSameResponseAndConsumed(): void
    {
        $this->api->shouldReceive('getBySearchParams')->once()
            ->andThrow(new EHealthConnectionException('Timeout'));

        $this->patientComponent()->call('search')
            ->assertSee(__('messages.connection_exception'))
            ->call('resetFilters')->assertDontSee(__('messages.connection_exception'));
    }

    public function testRepeatedSearchErrorsAreVisibleWithinTheSameSecond(): void
    {
        // Freeze timestamp keys so this regression does not depend on execution speed.
        $freezeMessageKey = true;
        Blade::precompiler(static function (string $source) use (&$freezeMessageKey): string {
            return $freezeMessageKey ? str_replace(':key="time()"', ':key="1234567890"', $source) : $source;
        });
        $layoutPath = resource_path('views/components/layouts/patient.blade.php');
        Blade::compile($layoutPath);

        try {
            $this->api->shouldReceive('getBySearchParams')->twice()
                ->andThrow(new EHealthConnectionException('Timeout'));
            $message = __('messages.connection_exception');

            $this->patientComponent()->call('search')->assertSee($message)
                ->call('resetFilters')->assertDontSee($message)
                ->call('search')->assertSee($message);
        } finally {
            $freezeMessageKey = false;
            Blade::compile($layoutPath);
        }
    }

    public function testEmptySyncReportsSuccessAndConsumesItsMessage(): void
    {
        $dictionary = Mockery::mock(DictionaryManager::class);
        $dictionary->shouldReceive('basics')->andReturn(BasicDictionaryCollection::make([
            ['name' => 'eHealth/encounter_classes', 'values' => []],
            ['name' => 'eHealth/ICPC2/actions', 'values' => []],
        ]));
        $dictionary->shouldReceive('deviceDefinitions')->andReturn(DeviceDefinitionCollection::make([]));
        $dictionary->shouldReceive('services')->andReturn(ServiceCollection::make([]));
        $this->instance(DictionaryManager::class, $dictionary);

        $response = new EHealthResponse(new Response(200, [], json_encode([
            'data' => [], 'paging' => ['page_number' => 1, 'total_pages' => 1],
        ], JSON_THROW_ON_ERROR)), static fn (): array => []);
        $this->api->shouldReceive('getBySearchParams')->once()
            ->with($this->person->uuid, ['performer_legal_entity' => $this->legalEntity->uuid])
            ->andReturn($response);
        $message = __('device-dispenses.messages.synced_successfully');

        $this->patientComponent()->call('sync')->assertSee($message)
            ->call('resetFilters')->assertDontSee($message);
    }

    private function patientComponent(): \Livewire\Features\SupportTesting\Testable
    {
        Livewire::component('device-dispenses-patient-view-test', DeviceDispensesForPatientViewTest::class);

        return Livewire::test('device-dispenses-patient-view-test', ['legalEntity' => $this->legalEntity, 'personId' => $this->person->id]);
    }

    private function response(string $uuid, string $status = 'completed'): EHealthResponse
    {
        $validator = new ReflectionMethod(DeviceDispenseApi::class, 'validateDeviceDispense');
        $api = new DeviceDispenseApi();

        return new EHealthResponse(new Response(200, [], json_encode(['data' => [
            'id' => $uuid, 'status' => $status, 'inserted_at' => '2026-10-20T09:00:00Z',
            'details' => [['quantity' => ['value' => 5, 'system' => 'device_unit', 'code' => 'piece']]],
        ]], JSON_THROW_ON_ERROR)), fn (EHealthResponse $response): array => $validator->invoke($api, $response));
    }
}

class DeviceDispensesForPatientViewTest extends DeviceDispenses
{
    protected function initializeComponent(): void
    {
        $this->dictionaries = ['device_definition_classification_type' => []];
    }
}
