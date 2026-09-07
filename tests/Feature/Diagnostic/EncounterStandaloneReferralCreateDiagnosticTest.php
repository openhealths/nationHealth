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
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
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

    public function test_create_encounter_draft_persists_standalone_service_request_with_program(): void
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
            ->andReturn($prequalifyResponse);
        $this->app->instance(PatientServiceRequest::class, $patientApi);

        $lifecycle = app(ReferralRequestLifecycleService::class);
        $employeeContext = $lifecycle->resolveEncounterEmployeeContext($this->encounter, $this->employee->id);

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
            'context_id' => $this->encounter->id,
            'person_id' => $this->person->id,
            'employee_id' => $this->employee->id,
        ]);

        $local = ServiceRequestRequest::query()->where('uuid', $draftUuid)->first();
        $this->assertNotNull($local);
        $this->assertNull($local->basedOnId);
    }
}
