<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostic;

use App\Classes\eHealth\Api\Patient\ServiceRequest as PatientServiceRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Person\EncounterStatus;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Standalone referral from finished encounter (no care plan) — the flow colleagues reported.
 *
 * @group diagnostic
 * @group referral
 */
class EncounterStandaloneReferralCreateDiagnosticTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected Employee $employee;

    protected Person $person;

    protected Encounter $encounter;

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
            'email' => 'enc_ref_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'OUTPATIENT')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'OUTPATIENT']);

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

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Олена',
            'last_name' => 'Коваль',
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $performer = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => $this->employee->uuid]);
        $episodeId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

        $this->encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => EncounterStatus::FINISHED->value,
            'episode_id' => $episodeId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'performer_id' => $performer->id,
            'ehealth_inserted_at' => now(),
        ]);
    }

    public function test_transfer_draft_round_trips_fhir_references_through_storage_and_signing(): void
    {
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $performer = (string) Str::uuid();
        $division = (string) Str::uuid();
        $prequalifyResponse = Mockery::mock(EHealthResponse::class);
        $prequalifyResponse->shouldReceive('getData')->andReturn(['data' => [['status' => 'VALID']]]);
        $this->mock(PatientServiceRequest::class, function ($mock) use ($performer, $division, $prequalifyResponse): void {
            $mock->shouldReceive('prequalify')->once()->withArgs(function (string $personUuid, array $payload) use ($performer, $division): bool {
                $this->assertSame($this->person->uuid, $personUuid);
                $this->assertSame([], $payload['programs']);
                $this->assertSame($performer, data_get($payload, 'service_request.performer.identifier.value'));
                $this->assertSame($division, data_get($payload, 'service_request.location_reference.identifier.value'));

                return true;
            })->andReturn($prequalifyResponse);
        });
        $context = $lifecycle->resolveEncounterEmployeeContext($this->encounter, $this->employee->id);
        $uuid = $lifecycle->createEncounterDraft($this->encounter, [
            'service_id' => (string) Str::uuid(),
            'category' => 'transfer_of_care',
            'performer' => $performer,
            'location_reference' => $division,
            'performer_type' => 'THERAPIST',
        ], 1, $context);
        $record = ServiceRequestRequest::where('uuid', $uuid)->firstOrFail();

        $this->assertSame($performer, $record->performer->value);
        $this->assertSame('legal_entity', data_get($record->performer->identifier, 'type.coding.0.code'));
        $this->assertSame('eHealth/resources', data_get($record->performer->identifier, 'type.coding.0.system'));
        $this->assertSame($division, $record->locationReference->value);
        $this->assertSame('division', data_get($record->locationReference->identifier, 'type.coding.0.code'));
        $this->assertSame('THERAPIST', $record->performerType->coding->first()->code);
        $this->assertSame('SPECIALITY_TYPE', $record->performerType->coding->first()->system);

        $data = $lifecycle->buildSignDbData($record, null, $this->encounter, $context);
        $payload = (new \App\Services\MedicalEvents\Mappers\ServiceRequestMapper())->toCreateSignedContent($data, [
            'person_uuid' => $this->person->uuid,
            'encounter_uuid' => $this->encounter->uuid,
            'employee_uuid' => $this->employee->uuid,
            'legal_entity_uuid' => $this->legalEntity->uuid,
        ]);
        $this->assertSame($performer, data_get($payload, 'performer.identifier.value'));
        $this->assertSame($division, data_get($payload, 'location_reference.identifier.value'));
        $this->assertSame('THERAPIST', data_get($payload, 'performer_type.coding.0.code'));

        $ids = [$record->performerId, $record->locationReferenceId, $record->performerTypeId];
        $repository = \App\Repositories\MedicalEvents\Repository::serviceRequest();
        $repository->store($data + ['status' => 'active'], $this->person->id);
        $record = $record->fresh();
        $this->assertSame($ids, [$record->performerId, $record->locationReferenceId, $record->performerTypeId]);

        unset($data['performer'], $data['location_reference'], $data['performer_type']);
        $repository->store($data + ['status' => 'active'], $this->person->id);
        $record = $record->fresh();
        $this->assertSame($ids, [$record->performerId, $record->locationReferenceId, $record->performerTypeId]);

        $repository->store($data + ['status' => 'active', 'performer' => null, 'location_reference' => null, 'performer_type' => null], $this->person->id);
        $record = $record->fresh();
        $this->assertNull($record->performerId);
        $this->assertNull($record->locationReferenceId);
        $this->assertNull($record->performerTypeId);
    }

    public function test_transfer_schema_supports_install_upgrade_idempotence_and_rollback(): void
    {
        $migration = require database_path('migrations/update/0_1/2026_10_01_142000_add_transfer_fields_to_service_request_requests_table.php');
        $columns = ['performer_id', 'location_reference_id', 'performer_type_id'];
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        $this->assertTrue($schema->hasColumns('service_request_requests', $columns));
        $migration->up();
        $migration->down();
        foreach ($columns as $column) {
            $this->assertFalse($schema->hasColumn('service_request_requests', $column));
        }
        $migration->up();
        $migration->up();
        $this->assertTrue($schema->hasColumns('service_request_requests', $columns));
        $this->assertFalse($schema->hasColumn('service_request_requests', 'performer_legal_entity_uuid'));
    }

    public static function legacyMigrationStates(): array
    {
        return ['pending update' => [false], 'previous update recorded' => [true]];
    }

    #[DataProvider('legacyMigrationStates')]
    public function testLegacyDatabaseUpgradesThroughMigratorAndCanCreateNormalReferral(bool $previousUpdateRecorded): void
    {
        $columns = ['performer_id', 'location_reference_id', 'performer_type_id'];
        Schema::table('service_request_requests', static function (Blueprint $table) use ($columns): void {
            foreach ($columns as $columnName) {
                $table->dropConstrainedForeignId($columnName);
            }
        });
        $existingRecord = ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'employee_id' => $this->employee->id,
            'person_id' => $this->person->id, 'status' => 'active',
            'service_id' => (string) Str::uuid(), 'quantity' => 1,
        ]);
        if ($previousUpdateRecorded) {
            app('migrator')->getRepository()->log('2026_10_01_142000_add_transfer_fields_to_service_request_requests_table', 1);
        }
        $migrationPaths = [
            database_path('migrations/update/0_1/2026_10_01_142000_add_transfer_fields_to_service_request_requests_table.php'),
            $this->transferUpgradePath(),
        ];

        $this->artisan('migrate', ['--path' => $migrationPaths, '--realpath' => true])->assertExitCode(0);

        $foreignKeys = collect(Schema::getForeignKeys('service_request_requests'))
            ->keyBy(static fn (array $foreignKey): string => implode(',', $foreignKey['columns']));
        foreach (['performer_id' => 'identifiers', 'location_reference_id' => 'identifiers', 'performer_type_id' => 'codeable_concepts'] as $columnName => $targetTable) {
            $this->assertTrue(Schema::hasColumn('service_request_requests', $columnName));
            $this->assertSame($targetTable, $foreignKeys[$columnName]['foreign_table']);
            $this->assertSame(['id'], $foreignKeys[$columnName]['foreign_columns']);
        }
        $this->assertSame('active', $existingRecord->fresh()->status);
        $this->assertSame($existingRecord->serviceId, $existingRecord->fresh()->serviceId);

        $lifecycle = app(ReferralRequestLifecycleService::class);
        $uuid = $lifecycle->createEncounterDraft($this->encounter, [
            'service_id' => (string) Str::uuid(), 'category' => 'diagnostic_procedure',
        ], 1, $lifecycle->resolveEncounterEmployeeContext($this->encounter, $this->employee->id));
        $draft = ServiceRequestRequest::whereUuid($uuid)->firstOrFail();
        $this->assertNull($draft->performerId);
        $this->assertNull($draft->locationReferenceId);
        $this->assertNull($draft->performerTypeId);

        $this->artisan('migrate', ['--path' => $migrationPaths, '--realpath' => true])->assertExitCode(0);
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_13_120000_ensure_referral_transfer_reference_columns')->count());

        $this->artisan('migrate:rollback', ['--path' => $migrationPaths, '--realpath' => true, '--step' => 1])->assertExitCode(0);
        foreach ($columns as $columnName) {
            $this->assertSame(!$previousUpdateRecorded, Schema::hasColumn('service_request_requests', $columnName));
        }
        $this->assertSame($existingRecord->uuid, $existingRecord->fresh()->uuid);
        $this->artisan('migrate', ['--path' => $migrationPaths, '--realpath' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumns('service_request_requests', $columns));
    }

    public function testFreshInstallationKeepsItsColumnsWhenUpgradeIsRolledBack(): void
    {
        $foreignKeys = Schema::getForeignKeys('service_request_requests');
        $this->artisan('migrate', ['--path' => $this->transferUpgradePath(), '--realpath' => true])->assertExitCode(0);
        $this->artisan('migrate:rollback', ['--path' => $this->transferUpgradePath(), '--realpath' => true, '--step' => 1])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumns('service_request_requests', ['performer_id', 'location_reference_id', 'performer_type_id']));
        $this->assertSame($foreignKeys, Schema::getForeignKeys('service_request_requests'));
    }

    public function testPartialSchemaRollbackRemovesOnlyTheNewlyAddedColumn(): void
    {
        Schema::table('service_request_requests', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('performer_type_id');
        });
        $this->artisan('migrate', ['--path' => $this->transferUpgradePath(), '--realpath' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('service_request_requests', 'performer_type_id'));
        $this->artisan('migrate:rollback', ['--path' => $this->transferUpgradePath(), '--realpath' => true, '--step' => 1])->assertExitCode(0);

        $this->assertFalse(Schema::hasColumn('service_request_requests', 'performer_type_id'));
        $this->assertTrue(Schema::hasColumns('service_request_requests', ['performer_id', 'location_reference_id']));
    }

    public function testCreateEncounterDraftPersistsStandaloneServiceRequestWithProgram(): void
    {
        $serviceId = (string) Str::uuid();
        $programId = (string) Str::uuid();

        $prequalifyResponse = Mockery::mock(EHealthResponse::class);
        $prequalifyResponse->shouldReceive('getData')->andReturn([
            'data' => [['status' => 'VALID']],
        ]);

        $patientApi = Mockery::mock(PatientServiceRequest::class)->makePartial();
        $patientApi->shouldReceive('prequalify')
            ->once()
            ->withArgs(function (string $personUuid, array $payload) use ($serviceId, $programId): bool {
                $this->assertSame($this->person->uuid, $personUuid);
                $this->assertSame($serviceId, $payload['service_request']['code']['identifier']['value']);
                $this->assertSame($this->encounter->uuid, $payload['service_request']['context']['identifier']['value']);
                $this->assertSame($programId, $payload['programs'][0]['identifier']['value']);
                $this->assertArrayNotHasKey('based_on', $payload['service_request']);
                $this->assertArrayNotHasKey('id', $payload['service_request']);

                return true;
            })
            ->andReturn($prequalifyResponse);
        $this->app->instance(PatientServiceRequest::class, $patientApi);

        $lifecycle = new \Tests\Support\EncounterReferralDraftHarness();
        $employeeContext = app(\App\Repositories\EmployeeRepository::class)->resolveEncounterEmployeeContext($this->encounter, $this->employee->id);

        $this->assertSame($this->employee->id, $employeeContext['employee_id']);

        $draftUuid = $lifecycle->createEncounterDraft(
            $this->encounter,
            [
                'kind' => 'service_request',
                'service_id' => $serviceId,
                'category' => 'diagnostic_procedure',
                'quantity' => 1,
                'priority' => 'routine',
                'started_at' => now()->format('d.m.Y'),
                'ended_at' => now()->addMonths(3)->format('d.m.Y'),
                'program_id' => $programId,
                'inform_with' => (string) Str::uuid(),
                'note' => 'standalone from encounter',
            ],
            1.0,
            $employeeContext
        );

        $this->assertNotEmpty($draftUuid);
        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $draftUuid,
            'service_id' => $serviceId,
            'program_id' => $programId,
            'status' => 'draft',
            'person_id' => $this->person->id,
            'employee_id' => $this->employee->id,
        ]);

        $local = ServiceRequestRequest::whereUuid($draftUuid)->first();
        $this->assertNotNull($local);
        $this->assertSame($this->encounter->uuid, $local->context?->value);
        $this->assertNull($local->basedOnId);
        $this->assertSame($this->encounter->uuid, $local->context->value);
    }

    private function transferUpgradePath(): string
    {
        return database_path('migrations/update/0_1/2026_10_13_120000_ensure_referral_transfer_reference_columns.php');
    }
}
