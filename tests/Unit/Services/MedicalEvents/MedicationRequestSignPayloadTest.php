<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class MedicationRequestSignPayloadTest extends TestCase
{
    use DatabaseTransactions;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    public function test_build_sign_payload_fallback_resolves_activity_and_care_plan_without_stored_ehealth_payload(): void
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Тест',
            'last_name' => 'Лікар',
            'tax_id' => '1234567890',
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'sign_fallback_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Тест Лікар',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status' => 'finished',
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Sign fallback plan',
            'status' => 'active',
        ]);

        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $employee->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'product_reference' => 'INN-101',
            'quantity' => 10.0,
            'program' => 'program-1',
        ]);

        $requestRecord = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'person_id' => $person->id,
            'status' => 'new',
            'medication_id' => 'INN-101',
            'medication_qty' => 10.0,
            'intent' => 'order',
            'category' => 'community',
            'based_on_id' => $activity->id,
            'context_id' => $encounter->id,
            'ehealth_payload' => null,
            'started_at' => now()->toDateString(),
            'ended_at' => now()->addDays(14)->toDateString(),
        ]);

        $requestRecord->dosageInstructions()->create([
            'sequence' => 1,
            'text' => 'По 1 таблетці',
            'patient_instruction' => 'По 1 таблетці',
            'route' => 'oral',
            'dose_and_rate' => json_encode([
                ['dose_quantity_value' => 1.0, 'dose_quantity_unit' => 'од.'],
            ]),
            'max_dose_per_administration' => 1.0,
            'max_dose_per_period' => 1.0,
        ]);

        $mockApi = Mockery::mock('alias:' . \App\Classes\eHealth\Api\MedicationRequest::class);
        $mockApi->shouldReceive('getRequestsBySearchParams')
            ->andThrow(new \RuntimeException('remote payload unavailable'));

        $service = app(MedicationRequestLifecycleService::class);
        $method = new ReflectionMethod($service, 'buildSignPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $carePlan->fresh(['person']), $requestRecord->fresh(['dosageInstructions']), '');

        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload);
        $this->assertSame('INN-101', data_get($payload, 'medication_id') ?? data_get($payload, 'medication.identifier.value'));
    }
}
