<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferralSignPayloadTest extends TestCase
{
    use DatabaseTransactions;

    protected Person $person;

    protected Encounter $encounter;

    protected Employee $employee;

    protected LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        Model::preventAccessingMissingAttributes();

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
                ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']),
            'is_active' => true,
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'sr_sign_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'legal_entity_id' => $this->legalEntity->id,
            'party_id' => $party->id,
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'position' => 'Doctor',
            'is_active' => true,
            'start_date' => now()->toDateString(),
        ]);

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1990-05-05',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

        $this->encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);
    }

    public function test_build_sign_db_data_for_encounter_draft_defaults_service_units(): void
    {
        $request = $this->createDraft();
        $serviceId = $request->serviceId;

        $payload = app(ReferralRequestLifecycleService::class)->buildSignDbData(
            $request,
            null,
            $this->encounter,
            [
                'employee_id' => $this->employee->id,
                'division_id' => null,
                'employee_uuid' => $this->employee->uuid,
                'legal_entity_uuid' => $this->legalEntity->uuid,
            ]
        );

        $this->assertSame('SERVICE_UNIT', $payload['quantity_system']);
        $this->assertSame('PIECE', $payload['quantity_code']);
        $this->assertSame($serviceId, $payload['service_id']);
        $this->assertNull($payload['based_on_id']);
        $this->assertSame($this->encounter->id, $payload['context_id']);
        $this->assertSame($request->patientInstruction, $payload['patient_instruction']);
        $this->assertSame($request->informWith, $payload['inform_with']);
    }

    public function test_build_sign_db_data_takes_quantity_units_from_care_plan_activity(): void
    {
        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->toDateString(),
            'title' => 'Sign payload plan',
            'status' => 'active',
            'encounter_id' => $this->encounter->id,
        ]);

        $productId = (string) Str::uuid();
        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'service_request',
            'product_reference' => $productId,
            'quantity' => 2,
            'quantity_system' => 'SERVICE_UNIT',
            'quantity_code' => 'PROCEDURE',
        ]);

        $request = $this->createDraft([
            'based_on_id' => $activity->id,
            'quantity' => 2,
            'service_id' => $productId,
        ]);

        $payload = app(ReferralRequestLifecycleService::class)->buildSignDbData(
            $request,
            $activity,
            $carePlan,
            [
                'employee_id' => $this->employee->id,
                'division_id' => null,
                'employee_uuid' => $this->employee->uuid,
                'legal_entity_uuid' => $this->legalEntity->uuid,
            ]
        );

        $this->assertSame('SERVICE_UNIT', $payload['quantity_system']);
        $this->assertSame('PROCEDURE', $payload['quantity_code']);
        $this->assertSame($activity->productReference, $payload['service_id']);
        $this->assertSame($activity->id, $payload['based_on_id']);
        $this->assertSame($activity->uuid, $payload['based_on_uuid']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDraft(array $overrides = []): ServiceRequestRequest
    {
        return ServiceRequestRequest::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'draft',
            'started_at' => '2026-08-12',
            'ended_at' => '2026-11-12',
            'service_id' => (string) Str::uuid(),
            'quantity' => 1,
            'intent' => 'order',
            'category' => 'diagnostic_procedure',
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'note' => 'standalone',
            'patient_instruction' => 'take documents',
            'inform_with' => (string) Str::uuid(),
        ], $overrides));
    }
}
