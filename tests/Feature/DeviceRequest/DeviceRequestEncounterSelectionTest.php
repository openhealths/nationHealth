<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Enums\Status;
use App\Livewire\EncounterPackage\EncounterPackageEdit;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Policies\DeviceRequestRequestPolicy;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DeviceRequestEncounterSelectionTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private LegalEntity $legalEntity;
    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 00:30:00', 'Europe/Kyiv'));
        config(['app.timezone' => 'Europe/Kyiv']);
        $this->legalEntity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $this->user = User::create(['uuid' => (string) Str::uuid(), 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password'), 'party_id' => $this->party()->id]);
        $this->instance('legalEntity', $this->legalEntity);
        setPermissionsTeamId($this->legalEntity->id);
        $this->actingAs($this->user);
    }

    public function test_today_list_includes_another_active_position_of_the_same_party_without_a_pivot(): void
    {
        $first = $this->employee();
        $this->user->employees()->attach($first);
        $performer = $this->employee();
        $encounter = $this->encounter($performer);
        $this->encounter($performer, now()->subDay()->utc()->toIso8601String());
        $this->encounter($performer, status: 'draft');
        $this->encounter($performer, person: Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']));
        $this->encounter($this->employee(['party_id' => $this->party()->id]));
        $this->encounter($this->employee(['legal_entity_id' => LegalEntity::create(['uuid' => (string) Str::uuid()])->id]));
        $this->encounter($this->employee(['status' => Status::STOPPED->value]));
        $this->encounter($this->employee(['is_active' => false]));
        $this->encounter($this->employee(['employee_type' => 'RECEPTIONIST']));
        $unlinked = $this->employee(['user_id' => null]);
        $this->encounter($unlinked);
        $this->assertNull(app(DeviceRequestRequestPolicy::class)->employee($this->user, 'device_request:write', $unlinked->uuid));

        $choices = Repository::deviceRequest()->eligibleEncounters($this->person, $this->user, $this->legalEntity);

        $this->assertSame([$encounter->id], $choices->pluck('id')->all());
        $selected = app(DeviceRequestRequestPolicy::class)->employee($this->user, 'device_request:write', $performer->uuid);
        $this->assertSame($performer->id, $selected->id);
        $this->assertSame($encounter->id, app(DeviceRequestLifecycleService::class)->encounter($this->person, $selected, $encounter->uuid)->id);
    }

    public function test_policy_refuses_foreign_party_or_stopped_employee_even_with_a_pivot(): void
    {
        foreach ([['party_id' => $this->party()->id], ['status' => Status::STOPPED->value], ['is_active' => false]] as $attributes) {
            $employee = $this->employee($attributes);
            $this->user->employees()->attach($employee);
            $this->assertNull(app(DeviceRequestRequestPolicy::class)->employee($this->user, 'device_request:write', $employee->uuid));
        }
    }

    public function test_drafts_from_each_own_active_position_remain_available(): void
    {
        $ownDrafts = [];
        foreach ([$this->employee(), $this->employee(), $this->employee(['party_id' => $this->party()->id])] as $index => $employee) {
            $draft = DeviceRequestRequest::create([
                'uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'person_id' => $this->person->id,
                'status' => 'draft', 'device_id' => 'test', 'request_payload' => ['data' => []],
            ]);
            if ($index < 2) {
                $ownDrafts[] = $draft->id;
            }
        }

        $drafts = Repository::deviceRequest()->draftsForPatientUser($this->person->id, $this->user, $this->legalEntity);

        $this->assertEqualsCanonicalizing($ownDrafts, $drafts->pluck('id')->all());
    }

    public function test_encounter_page_uses_its_performer_and_redirects_to_the_form(): void
    {
        $performer = $this->employee();
        $encounter = $this->encounter($performer);
        Gate::before(static fn () => true);
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('authorizeAction')->once()->with($this->legalEntity, 'device_request:write', $performer->uuid)->andReturn($performer);
        $this->instance(DeviceRequestLifecycleService::class, $service);
        $component = $this->encounterComponent($encounter);

        $component->openDeviceRequest();

        $this->assertSame('persons.device-requests.create', $component->redirectedRoute);
        $this->assertSame($encounter->id, $component->redirectedParameters['encounter']);
    }

    public function test_denied_encounter_action_flashes_an_error_before_calling_the_service(): void
    {
        Gate::before(static fn () => false);
        $service = Mockery::mock(DeviceRequestLifecycleService::class);
        $service->shouldNotReceive('authorizeAction');
        $service->shouldNotReceive('encounter');
        $this->instance(DeviceRequestLifecycleService::class, $service);
        $component = new DeviceRequestEncounterHarness();
        $component->personId = $this->person->id;

        $component->openDeviceRequest();

        $this->assertNull($component->redirectedRoute);
        $this->assertSame(__('device-requests.policy.perform'), Session::get('error'));
    }

    public function test_yesterdays_encounter_is_rejected_at_submission(): void
    {
        $employee = $this->employee();
        $encounter = $this->encounter($employee, now()->subDay()->utc()->toIso8601String());
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->encounter($this->person, $employee, $encounter->uuid);
    }

    private function party(): Party
    {
        return Party::create(['uuid' => (string) Str::uuid(), 'first_name' => 'Test', 'last_name' => 'Doctor']);
    }

    private function employee(array $attributes = []): Employee
    {
        return Employee::create(array_replace([
            'uuid' => (string) Str::uuid(), 'employee_type' => 'SPECIALIST', 'status' => Status::APPROVED->value,
            'legal_entity_id' => $this->legalEntity->id, 'party_id' => $this->user->partyId, 'user_id' => $this->user->id,
            'is_active' => true, 'position' => 'P1', 'start_date' => today()->toDateString(),
        ], $attributes));
    }

    private function encounter(Employee $employee, ?string $end = null, string $status = 'finished', ?Person $person = null): Encounter
    {
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => ($person ?? $this->person)->id, 'status' => $status,
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id,
        ]);
        $end ??= now()->utc()->toIso8601String();
        $encounter->period()->create(['start' => $end, 'end' => $end]);

        return $encounter;
    }

    private function encounterComponent(Encounter $encounter): DeviceRequestEncounterHarness
    {
        $component = new DeviceRequestEncounterHarness();
        $component->personId = $this->person->id;
        $component->encounterId = $encounter->id;
        $component->encounterUuid = $encounter->uuid;

        return $component;
    }
}

class DeviceRequestEncounterHarness extends EncounterPackageEdit
{
    public ?string $redirectedRoute = null;
    public array $redirectedParameters = [];

    public function redirectRoute($name, $parameters = [], $absolute = true, $navigate = false)
    {
        $this->redirectedRoute = $name;
        $this->redirectedParameters = $parameters;
    }
}
