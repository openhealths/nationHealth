<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\Api\Configuration as ConfigurationApi;
use App\Classes\eHealth\Api\Patient\Composition as CompositionApi;
use App\Classes\eHealth\Api\Patient\Encounter as EncounterApi;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\SignatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Permission;

/**
 * Shared setup for the medical conclusion tests.
 *
 * The wizard only becomes reachable once a whole chain of records lines up — legal
 * entity of the right type, an approved employee of the right role, the scopes, and the
 * permission team — so building that chain is factored out rather than repeated.
 */
trait CompositionTestFixtures
{
    /**
     * Put the user inside a conclusion-issuing legal entity.
     *
     * @param  list<string>  $scopes
     * @return array{legalEntity: LegalEntity, person: Person, user: User, employee: Employee}
     */
    protected function compositionFixture(
        string $entityType = LegalEntity::TYPE_OUTPATIENT,
        Role $role = Role::SPECIALIST,
        array $scopes = ['composition:create', 'composition:read', 'composition:search'],
    ): array {
        $typeId = DB::table('legal_entity_types')->where('name', $entityType)->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => $entityType]);

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
            'email' => 'doctor+' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Ольга Лікарівна',
            'employee_type' => $role->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => $role === Role::SPECIALIST ? 'P8' : 'P10',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $user->employees()->attach($employee->id);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '2001-02-23',
            'gender' => 'MALE',
        ]);
        $person->names()->create([
            'last_name' => 'Якийсь',
            'first_name' => 'Пацієнт',
            'language' => 'uk',
        ]);

        $this->instance('legalEntity', $legalEntity);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        if ($scopes !== []) {
            $user->givePermissionToParent(
                ...array_map(
                    static fn (string $scope) => Permission::findOrCreate($scope, 'web'),
                    $scopes
                )
            );
        }

        $this->actingAs($user);

        return compact('legalEntity', 'person', 'user', 'employee');
    }

    protected function compositionPreperson(): Preperson
    {
        return Preperson::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Невідомий',
            'last_name' => 'Пацієнт',
            'gender' => 'MALE',
        ]);
    }

    /**
     * Stand in for the KEP service.
     *
     * The signature itself cannot be produced in a test, but the requirement that the
     * payload passes through the signer — and that what reaches eHealth is the signer's
     * output — is exactly what the mock lets the assertions check.
     */
    protected function fakeSignatureService(string $signed = 'base64-signed-payload'): void
    {
        $signer = Mockery::mock(SignatureService::class);
        $signer->shouldReceive('signData')->andReturn($signed);
        // The modal lists the certificate authorities while it renders.
        $signer->shouldReceive('getCertificateAuthorities')->andReturn([]);

        $this->instance(SignatureService::class, $signer);
    }

    /**
     * Register HTTP stubs and make the Encounter API honour them.
     *
     * `EHealth::encounter()` builds a client of its own, which would otherwise bypass
     * `Http::fake()` entirely. The stub has to be re-attached after every `Http::fake()`
     * call, since faking replaces the recorded callbacks.
     *
     * @param  array<string, mixed>  $routes  Passed straight to `Http::fake()`.
     */
    protected function fakeEHealth(array $routes): void
    {
        $factory = Http::getFacadeRoot();

        // Http::fake() appends to the existing stubs, so a catch-all registered by an
        // earlier call would keep answering the routes this call is trying to define.
        (function (): void {
            $this->stubCallbacks = collect();
        })->call($factory);

        Http::fake($routes);

        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);

        foreach ([EncounterApi::class, CompositionApi::class, PersonApi::class, ConfigurationApi::class] as $class) {
            $api = new $class($factory);
            $api->stub($stubs);

            $this->instance($class, $api);
        }
    }

    /**
     * An eHealth list response wrapper.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function eHealthBody(array $data): array
    {
        return ['data' => $data, 'meta' => [], 'paging' => []];
    }

    /**
     * An encounter shaped the way the Encounter API validator insists on.
     *
     * @return array<string, mixed>
     */
    protected function compositionEncounter(
        string $uuid,
        string $performerUuid,
        ?string $episodeUuid = null,
        string $status = 'finished'
    ): array {
        return [
            'id' => $uuid,
            'status' => $status,
            'inserted_at' => '2026-08-01T09:00:00Z',
            'updated_at' => '2026-08-01T09:30:00Z',
            'class' => ['system' => 'eHealth/encounter_classes', 'code' => 'AMB'],
            'type' => ['coding' => [['system' => 'eHealth/encounter_types', 'code' => 'AMB']]],
            'period' => ['start' => '2026-08-01T09:00:00Z', 'end' => '2026-08-01T09:30:00Z'],
            'performer' => $this->compositionReference('employee', $performerUuid),
            'episode' => $this->compositionReference('episode_of_care', $episodeUuid ?? (string) Str::uuid()),
            'visit' => $this->compositionReference('visit', (string) Str::uuid()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function compositionReference(string $resource, string $uuid): array
    {
        return [
            'identifier' => [
                'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $resource]]],
                'value' => $uuid,
            ],
        ];
    }
}
