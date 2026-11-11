<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Classes\eHealth\Api\Configuration as ConfigurationApi;
use App\Classes\eHealth\Api\Patient\Composition as CompositionApi;
use App\Classes\eHealth\Api\Patient\Encounter as EncounterApi;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Jobs\CompositionAsyncProcessing;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\Permission;
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
use Spatie\Permission\Models\Role as PermissionRole;

/**
 * Shared setup for the medical conclusion tests.
 *
 * The wizard only becomes reachable once a whole chain of records lines up — legal
 * entity of the right type, an approved employee of the right role, the scopes, and the
 * permission team — so building that chain is factored out rather than repeated.
 */
trait CompositionTestFixtures
{
    use UsesTv39CompositionConfiguration;

    protected function nativeAuthenticationMethod(string $uuid, string $type = 'OTP'): array
    {
        $method = [
            'id' => $uuid, 'type' => $type, 'phone_number' => '+380501234567',
            'inserted_at' => now()->utc()->toIso8601String(), 'inserted_by' => (string) Str::uuid(),
        ];
        if ($type === 'THIRD_PERSON') {
            unset($method['phone_number']);
            $method['value'] = (string) Str::uuid();
        }

        return $method;
    }
    /** Run the real background client and processor without starting a queue worker. */
    protected function processCompositionWorker(string $jobId): void
    {
        $operation = CompositionOperation::whereRemoteJobId($jobId)->firstOrFail();
        $worker = new class ($operation, legalEntity()) extends CompositionAsyncProcessing
        {
            public function processForTest(): void
            {
                $this->token = 'synthetic-token';
                $this->processResponse($this->sendRequest($this->token));
            }
        };
        $worker->processForTest();
    }

    protected function processAllCompositionWorkers(): void
    {
        foreach (CompositionOperation::pending()->pluck('remote_job_id') as $jobId) {
            $this->processCompositionWorker($jobId);
        }
    }

    /** Complete successful native document; callers supply deliberate test identities. */
    protected function nativeCompositionDocument(array $overrides = []): array
    {
        $uuid = '89678f60-4cdc-4fe3-ae83-e8b3ebd35c59';
        $reference = $this->compositionReference('person', $uuid)['identifier'];
        $document = [
            'identifier' => $this->compositionReference('composition', $uuid)['identifier'],
            'status' => 'PRELIMINARY', 'title' => 'Test conclusion', 'date' => '2026-09-05T10:00:00Z',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
            'category' => ['coding' => [['system' => 'COMPOSITION_CATEGORIES', 'code' => 'SICKNESS']]],
            'author' => $this->compositionReference('employee', $uuid)['identifier'],
            'subject' => $reference,
            'custodian' => $this->compositionReference('legal_entity', $uuid)['identifier'],
            'encounter' => $this->compositionReference('encounter', $uuid)['identifier'],
            'section' => ['focus' => $reference], 'extension' => [], 'relatesTo' => [],
            'event' => [['period' => ['start' => '2026-09-01T00:00:01Z', 'end' => '2026-09-05T20:59:59Z']]],
        ];
        foreach (['identifier', 'author', 'subject', 'custodian', 'encounter'] as $field) {
            if (isset($overrides[$field])) {
                $overrides[$field] = array_replace($document[$field], $overrides[$field]);
            }
        }
        if (isset($overrides['section']['focus'])) {
            $overrides['section']['focus'] = array_replace($reference, $overrides['section']['focus']);
        }
        if (isset($overrides['relatesTo']['targetIdentifier'])) {
            $overrides['relatesTo']['targetIdentifier'] = array_replace($reference, $overrides['relatesTo']['targetIdentifier']);
        }

        return array_replace($document, $overrides);
    }

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

        $employee->specialities()->create(['speciality' => 'OBSTETRICS_AND_GYNECOLOGY', 'speciality_officio' => true,
            'level' => 'FIRST', 'attestation_name' => 'Synthetic qualification',
            'attestation_date' => '2020-01-01', 'certificate_number' => 'TEST-001']);
        $user->employees()->attach($employee->id);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '2001-02-23',
            'gender' => 'FEMALE',
            'verification_status' => 'VERIFIED',
            'no_tax_id' => false,
            'tax_id' => '1234567890',
        ]);
        $person->names()->create([
            'last_name' => 'Якийсь',
            'first_name' => 'Пацієнт',
            'language' => 'uk',
        ]);
        $person->documents()->create(['type' => 'PASSPORT', 'number' => 'АА123456']);

        foreach ($scopes as $scope) {
            $permission = Permission::findOrCreate($scope, 'web');
            $permission->legalEntityTypes()->syncWithoutDetaching([$typeId]);
        }

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

        if ($scopes !== []) {
            $permissionRole = PermissionRole::findOrCreate($role->value, 'web');
            $permissionRole->syncPermissions($scopes);
            $user->assignRole($permissionRole);
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
            'status' => 'active',
            'birth_date' => today()->format('Y-m-d'),
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
        return ['data' => $data, 'meta' => [], 'paging' => [
            'page_number' => 1, 'page_size' => max(1, count($data)),
            'total_entries' => count($data), 'total_pages' => $data === [] ? 0 : 1,
        ]];
    }

    /**
     * Complete native Medical Events encounter, including required list/detail fields.
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
            'inserted_at' => now()->startOfDay()->addHours(9)->toIso8601String(),
            'updated_at' => now()->startOfDay()->addHours(9)->addMinutes(30)->toIso8601String(),
            'class' => ['system' => 'eHealth/encounter_classes', 'code' => 'AMB'],
            'type' => ['coding' => [['system' => 'eHealth/encounter_types', 'code' => 'service_delivery_location']]],
            'period' => ['start' => now()->startOfDay()->addHours(9)->toIso8601String(), 'end' => now()->startOfDay()->addHours(9)->addMinutes(30)->toIso8601String()],
            'reasons' => [],
            'actions' => [],
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
            'display_value' => 'Test reference',
            'identifier' => [
                'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $resource]]],
                'value' => $uuid,
            ],
        ];
    }
}
