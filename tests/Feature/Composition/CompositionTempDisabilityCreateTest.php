<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\Api\Patient\Encounter as EncounterApi;
use App\Enums\Person\CompositionCategory;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Composition\CompositionTempDisabilityCreate;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers mounting, rendering and the step transitions of the disability conclusion wizard.
 *
 * Rendering is checked through the real routes rather than `Livewire::test()`, because the
 * component receives its patient by route model binding and Livewire's test helper does
 * not reproduce that for a `Person` mount parameter. Interactions are then driven through
 * `Livewire::test()` with a preperson, which it does bind, and none of the asserted
 * behaviour depends on which of the two the patient is.
 */
class CompositionTempDisabilityCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
            ],
            '--realpath' => true,
        ]);
    }

    public function test_the_wizard_opens_on_the_encounter_step(): void
    {
        ['legalEntity' => $legalEntity] = $this->fixture();
        $this->fakeEncounters();

        Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->preperson(),
        ])
            ->assertOk()
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_ENCOUNTER);
    }

    /**
     * Renders the later steps so that a template error in them fails here rather than in
     * front of a doctor mid-flow.
     */
    public function test_the_details_and_review_steps_render(): void
    {
        ['legalEntity' => $legalEntity] = $this->fixture();
        $this->fakeEncounters();

        $component = Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->preperson(),
        ]);

        $component->call('skipAuthMethod')
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_DETAILS)
            ->assertOk();

        $component->set('compositionDetail', [
            'identifier' => ['value' => (string) Str::uuid()],
            'status' => 'PRELIMINARY',
            'title' => 'ТН-0001',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'event' => [['period' => ['start' => '2026-08-01T00:00:01Z', 'end' => '2026-08-10T20:59:59Z']]],
            'extension' => [['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true]],
        ])
            ->set('step', CompositionTempDisabilityCreate::STEP_REVIEW)
            ->assertOk()
            ->assertSee('ТН-0001');
    }

    /**
     * Only encounters the user performed themselves may carry a conclusion (TV 3.8.2.5.1),
     * and eHealth refuses one that is not finished.
     */
    public function test_only_finished_encounters_performed_by_the_user_are_offered(): void
    {
        ['legalEntity' => $legalEntity, 'employee' => $employee] = $this->fixture();

        $mine = (string) Str::uuid();

        $this->fakeEncounters([
            $this->encounter($mine, 'finished', $employee->uuid),
            $this->encounter((string) Str::uuid(), 'finished', (string) Str::uuid()),
            $this->encounter((string) Str::uuid(), 'entered_in_error', $employee->uuid),
        ]);

        $offered = Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->preperson(),
        ])->instance()->availableEncounters();

        $this->assertCount(1, $offered);
        $this->assertSame($mine, $offered->first()['uuid']);
    }

    public function test_choosing_an_encounter_records_its_episode_and_advances(): void
    {
        ['legalEntity' => $legalEntity, 'employee' => $employee] = $this->fixture();

        $encounterUuid = (string) Str::uuid();
        $episodeUuid = (string) Str::uuid();

        $this->fakeEncounters([
            $this->encounter($encounterUuid, 'finished', $employee->uuid, $episodeUuid),
        ]);

        Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->preperson(),
        ])
            ->call('selectEncounter', $encounterUuid)
            ->assertSet('form.encounterUuid', $encounterUuid)
            // The episode is what makes the conclusion readable back from eHealth.
            ->assertSet('episodeUuid', $episodeUuid)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_AUTH_METHOD);
    }

    public function test_selecting_an_encounter_that_is_not_on_offer_is_refused(): void
    {
        ['legalEntity' => $legalEntity] = $this->fixture();
        $this->fakeEncounters();

        Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->preperson(),
        ])
            ->call('selectEncounter', (string) Str::uuid())
            ->assertSet('form.encounterUuid', '')
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_ENCOUNTER);
    }

    public function test_the_patient_starts_as_their_own_incapacitated_person(): void
    {
        ['legalEntity' => $legalEntity] = $this->fixture();
        $this->fakeEncounters();
        $preperson = $this->preperson();

        Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $preperson,
        ])
            ->assertSet('form.subjectUuid', $preperson->uuid)
            ->assertSet('form.sectionFocusUuid', $preperson->uuid)
            ->assertSet('form.isUnidentified', true)
            ->assertSet('form.category', CompositionCategory::SICKNESS->value);
    }

    /**
     * TV 3.8.2.6 limits an unidentified patient to sickness and child care.
     */
    public function test_an_unidentified_patient_is_offered_only_the_permitted_categories(): void
    {
        ['legalEntity' => $legalEntity] = $this->fixture();
        $this->fakeEncounters();

        $offered = array_keys(
            Livewire::test(CompositionTempDisabilityCreate::class, [
                'legalEntity' => $legalEntity,
                'preperson' => $this->preperson(),
            ])->instance()->categoryOptions()
        );

        $this->assertSame([], array_diff($offered, ['SICKNESS', 'CHILD_CARE']));
        $this->assertNotContains(CompositionCategory::PREGNANCY->value, $offered);
    }

    /**
     * eHealth rejects a preperson that carries an authentication method, so the step must
     * not go looking for one.
     */
    public function test_authentication_methods_are_not_requested_for_an_unidentified_patient(): void
    {
        ['legalEntity' => $legalEntity] = $this->fixture();
        $this->fakeEncounters();

        Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->preperson(),
        ])
            ->call('loadAuthMethods')
            ->assertSet('authMethods', []);
    }

    public function test_skipping_the_authentication_method_records_the_acknowledgement(): void
    {
        ['legalEntity' => $legalEntity] = $this->fixture();
        $this->fakeEncounters();

        Livewire::test(CompositionTempDisabilityCreate::class, [
            'legalEntity' => $legalEntity,
            'preperson' => $this->preperson(),
        ])
            ->call('skipAuthMethod')
            ->assertSet('form.informWithUuid', null)
            ->assertSet('acknowledgedMissingAuthMethod', true)
            ->assertSet('step', CompositionTempDisabilityCreate::STEP_DETAILS);
    }

    /**
     * @param  list<array<string, mixed>>  $encounters
     */
    private function fakeEncounters(array $encounters = []): void
    {
        Http::fake(['*' => Http::response(['data' => $encounters, 'meta' => [], 'paging' => []], 200)]);

        $factory = Http::getFacadeRoot();
        $api = new EncounterApi($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));

        $this->instance(EncounterApi::class, $api);
    }

    /**
     * An encounter shaped the way the Encounter API validator insists on.
     *
     * Every reference has to be a full `{identifier: {type: {coding: [...]}, value}}`, and
     * the required fields must all be present, otherwise the validator drops the record
     * and the picker legitimately has nothing to show.
     *
     * @return array<string, mixed>
     */
    private function encounter(
        string $uuid,
        string $status,
        string $performerUuid,
        ?string $episodeUuid = null
    ): array {
        return [
            'id' => $uuid,
            'status' => $status,
            'inserted_at' => '2026-08-01T09:00:00Z',
            'updated_at' => '2026-08-01T09:30:00Z',
            'class' => ['system' => 'eHealth/encounter_classes', 'code' => 'AMB'],
            'type' => ['coding' => [['system' => 'eHealth/encounter_types', 'code' => 'AMB']]],
            'period' => ['start' => '2026-08-01T09:00:00Z', 'end' => '2026-08-01T09:30:00Z'],
            'performer' => $this->reference('employee', $performerUuid),
            'episode' => $this->reference('episode_of_care', $episodeUuid ?? (string) Str::uuid()),
            'visit' => $this->reference('visit', (string) Str::uuid()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(string $resource, string $uuid): array
    {
        return [
            'identifier' => [
                'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $resource]]],
                'value' => $uuid,
            ],
        ];
    }

    private function preperson(): Preperson
    {
        return Preperson::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Невідомий',
            'last_name' => 'Пацієнт',
            'gender' => 'MALE',
        ]);
    }

    /**
     * @return array{legalEntity: LegalEntity, person: Person, user: User, employee: Employee}
     */
    private function fixture(): array
    {
        $typeId = DB::table('legal_entity_types')->where('name', LegalEntity::TYPE_OUTPATIENT)->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => LegalEntity::TYPE_OUTPATIENT]);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ольга',
            'last_name' => 'Лікарівна',
            'tax_id' => '1234567890',
            'birth_date' => '1985-05-05',
            'gender' => 'FEMALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Ольга Лікарівна',
            'employee_type' => Role::SPECIALIST->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $user->employees()->attach($employee->id);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Пацієнт',
            'last_name' => 'Якийсь',
            'birth_date' => '2001-02-23',
            'gender' => 'MALE',
        ]);

        $this->instance('legalEntity', $legalEntity);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $user->givePermissionToParent(
            Permission::findOrCreate('composition:create', 'web'),
            Permission::findOrCreate('composition:read', 'web'),
        );

        $this->actingAs($user);

        return compact('legalEntity', 'person', 'user', 'employee');
    }
}
