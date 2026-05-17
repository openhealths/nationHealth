<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Person\Person;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Models\User;
use App\Models\Relations\Party;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Enums\CarePlanStatus;

class SeedCarePlanForTesting extends Command
{
    protected $signature = 'app:seed-care-plan';
    protected $description = 'Seed a patient with a signed encounter and a signed care plan for rapid manual testing';

    public function handle()
    {
        $this->info('🚀 Starting seeding process for Care Plan testing...');

        DB::transaction(function () {
            // 1. Get or Create Legal Entity
            $legalEntity = LegalEntity::first() ?? LegalEntity::create([
                'uuid' => (string) Str::uuid(),
                'status' => 'ACTIVE',
                'sync_status' => 'COMPLETED',
                'legal_entity_type_id' => 1,
                'is_active' => true,
            ]);

            // 2. Get or Create Doctor (User + Employee)
            $user = User::where('email', 'test-doctor@example.com')->first();
            if (!$user) {
                $party = Party::create([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => 'Тестовий',
                    'last_name' => 'Лікар',
                    'tax_id' => '1234567890',
                    'birth_date' => '1980-01-01',
                    'gender' => 'MALE',
                ]);
                $user = User::create([
                    'uuid' => (string) Str::uuid(),
                    'email' => 'test-doctor@example.com',
                    'password' => bcrypt('password'),
                    'party_id' => $party->id,
                ]);
                $employee = Employee::create([
                    'uuid' => (string) Str::uuid(),
                    'full_name' => 'Тестовий Лікар',
                    'employee_type' => 'DOCTOR',
                    'status' => 'APPROVED',
                    'legal_entity_id' => $legalEntity->id,
                    'is_active' => true,
                    'position' => 'Лікар',
                    'start_date' => now()->format('Y-m-d'),
                    'user_id' => $user->id,
                    'party_id' => $party->id,
                ]);
                $user->employees()->attach($employee->id);
            }
            $employee = $user->getCarePlanWriterEmployee();

            // 3. Create Patient
            $person = Person::create([
                'uuid' => (string) Str::uuid(),
                'first_name' => 'Тестовий',
                'last_name' => 'Пацієнт_' . Str::random(4),
                'birth_date' => '1995-05-05',
                'gender' => 'FEMALE',
                'patient_signed' => true,
                'process_disclosure_data_consent' => true,
                'legal_entity_id' => $legalEntity->id,
            ]);

            // 4. Create Signed Encounter
            $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
            $codingId = \App\Models\MedicalEvents\Sql\Coding::firstOrCreate(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id;
            $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

            $encounter = Encounter::create([
                'uuid' => (string) Str::uuid(),
                'person_id' => $person->id,
                'status' => 'finished',
                'class_id' => $codingId,
                'type_id' => $ccId,
                'author_id' => $employee->id,
                'legal_entity_id' => $legalEntity->id,
                'ehealth_inserted_at' => now(),
                'period_start' => now()->subHour(),
                'period_end' => now(),
            ]);

            // 5. Create Active Care Plan
            $carePlan = CarePlan::create([
                'uuid' => (string) Str::uuid(),
                'person_id' => $person->id,
                'author_id' => $employee->id,
                'legal_entity_id' => $legalEntity->id,
                'status' => CarePlanStatus::ACTIVE->value,
                'title' => 'План лікування: Терапія Гіпертензії ' . now()->format('d.m.Y'),
                'period_start' => now(),
                'period_end' => now()->addMonths(6),
                'encounter_id' => $encounter->id,
                'encounter_identifier_id' => $identifierId,
            ]);

            $this->info('✅ Patient created: ' . $person->full_name);
            $this->info('✅ Encounter created: ' . $encounter->uuid);
            $this->info('✅ Care Plan created: ' . $carePlan->title);
            
            $url = route('persons.care-plan.show', [$legalEntity->uuid, $person->id, $carePlan->uuid]);
            $this->warn('👉 Test URL: ' . $url);
        });

        $this->info('🎉 Seeding completed successfully!');
    }
}
