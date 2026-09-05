<?php

declare(strict_types=1);

namespace Tests\Feature\Services\MedicalEvents;

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
use Tests\TestCase;

class MedicationRequestLifecycleServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected Person $person;

    protected CarePlan $carePlan;

    protected CarePlanActivity $activity;

    protected Employee $employee;

    protected Encounter $encounter;

    protected User $user;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1985-05-15',
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
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'mr_lifecycle_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Д-р Іван Петренко',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);

        $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id;
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

        $this->carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Lifecycle plan',
            'status' => 'active',
        ]);

        $this->activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $this->carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'product_reference' => 'INN-101',
            'quantity' => 30.0,
            'program' => 'program-1',
        ]);
    }

    public function test_reject_successfully_rejects_new_request(): void
    {
        $requestRecord = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'new',
            'medication_id' => 'INN-101',
            'medication_qty' => 10.0,
            'intent' => 'order',
            'based_on_id' => $this->activity->id,
            'context_id' => $this->encounter->id,
        ]);

        $mockApi = Mockery::mock('alias:' . \App\Classes\eHealth\Api\MedicationRequest::class);
        $mockApi->shouldReceive('rejectUnsignedMedicationRequest')
            ->once()
            ->with((string) $requestRecord->uuid, [])
            ->andReturn(['status' => 'rejected']);

        $service = app(MedicationRequestLifecycleService::class);
        $service->rejectPrescription($this->carePlan->fresh(['person']), $requestRecord);

        $this->assertDatabaseHas('medication_request_requests', [
            'uuid' => $requestRecord->uuid,
            'status' => 'rejected',
        ]);
    }

    public function test_reject_active_sends_reason_code_and_signed_content(): void
    {
        $requestRecord = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'medication_id' => 'INN-101',
            'medication_qty' => 10.0,
            'intent' => 'order',
            'based_on_id' => $this->activity->id,
            'context_id' => $this->encounter->id,
        ]);

        $activeMedicationRequest = [
            'id' => (string) $requestRecord->uuid,
            'status' => 'ACTIVE',
            'request_number' => '0000-AAAA-BBBB-CCCC',
            'intent' => 'order',
        ];

        $mockApi = Mockery::mock('alias:' . \App\Classes\eHealth\Api\MedicationRequest::class);
        $mockApi->shouldReceive('getBySearchParams')->andReturn([]);
        $mockApi->shouldReceive('getById')
            ->once()
            ->andReturn($activeMedicationRequest);
        $mockApi->shouldReceive('rejectMedicationRequest')
            ->once()
            ->withArgs(function (string $id, array $payload): bool {
                return ($payload['signed_content'] ?? null) === 'mock-base64-signature'
                    && ($payload['signed_content_encoding'] ?? null) === 'base64'
                    && !empty($payload['person_id'])
                    && !array_key_exists('reject_reason_code', $payload)
                    && !array_key_exists('reject_reason', $payload);
            })
            ->andReturn(['status' => 'rejected']);

        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldReceive('signData')
            ->once()
            ->withArgs(function (array $signPayload) use ($activeMedicationRequest): bool {
                return ($signPayload['reject_reason_code'] ?? null) === 'WRONG_DOSAGE'
                    && ($signPayload['id'] ?? null) === $activeMedicationRequest['id']
                    && ($signPayload['status'] ?? null) === 'ACTIVE'
                    && ($signPayload['request_number'] ?? null) === '0000-AAAA-BBBB-CCCC';
            })
            ->andReturn('mock-base64-signature');

        $service = app(MedicationRequestLifecycleService::class);
        $result = $service->rejectPrescription(
            $this->carePlan->fresh(['person']),
            $requestRecord,
            [
                'password' => '12345678',
                'knedp' => 'acsk_test',
                'signer_tax_id' => '9876543210',
            ],
            'WRONG_DOSAGE'
        );

        $this->assertSame('rejected', $result['status'] ?? null);
        $this->assertDatabaseHas('medication_request_requests', [
            'uuid' => $requestRecord->uuid,
            'status' => 'rejected',
        ]);
    }

    public function test_reject_active_stops_when_ehealth_denies_access(): void
    {
        $requestRecord = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'medication_id' => 'INN-101',
            'medication_qty' => 10.0,
            'intent' => 'order',
            'based_on_id' => $this->activity->id,
            'context_id' => $this->encounter->id,
        ]);

        $forbidden = new \App\Exceptions\EHealth\EHealthResponseException(
            new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(403, ['Content-Type' => 'application/json'], json_encode([
                    'error' => ['message' => 'Access denied', 'type' => 'forbidden'],
                ]))
            )
        );

        $mockApi = Mockery::mock('alias:'.\App\Classes\eHealth\Api\MedicationRequest::class);
        $mockApi->shouldReceive('getBySearchParams')->andThrow($forbidden);
        $mockApi->shouldReceive('getById')->andThrow($forbidden);
        $mockApi->shouldNotReceive('rejectMedicationRequest');

        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldNotReceive('signData');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('care-plan.eprescription_reject_wrong_legal_entity'));

        app(MedicationRequestLifecycleService::class)->rejectPrescription(
            $this->carePlan->fresh(['person']),
            $requestRecord,
            [
                'password' => '12345678',
                'knedp' => 'acsk_test',
                'signer_tax_id' => '9876543210',
            ],
            'WRONG_DOSAGE'
        );
    }

    public function test_reject_active_refuses_to_sign_without_signer_tax_id(): void
    {
        $requestRecord = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'medication_id' => 'INN-101',
            'medication_qty' => 10.0,
            'intent' => 'order',
            'based_on_id' => $this->activity->id,
            'context_id' => $this->encounter->id,
        ]);

        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldNotReceive('signData');

        // An authenticated session must not be able to stand in for the missing form value.
        $this->actingAs($this->user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.signer_tax_id_required'));

        app(MedicationRequestLifecycleService::class)->rejectPrescription(
            $this->carePlan->fresh(['person']),
            $requestRecord,
            [
                'password' => '12345678',
                'knedp' => 'acsk_test',
            ],
            'entered-in-error'
        );
    }

    public function test_build_fallback_printout_html(): void
    {
        $service = app(MedicationRequestLifecycleService::class);
        $carePlan = new CarePlan();

        $uuid = '00000000-0000-4000-8000-000000001234';
        $html = $service->buildFallbackPrintoutHtml($carePlan, $uuid, 'Тестова інструкція');

        $this->assertStringContainsString($uuid, $html);
        $this->assertStringContainsString('Тестова інструкція', $html);
        $this->assertStringContainsString('(ПАМ\'ЯТКА)', $html);
    }

    public function test_build_fallback_printout_html_uses_doctor_name_passed_by_caller(): void
    {
        $service = app(MedicationRequestLifecycleService::class);

        $html = $service->buildFallbackPrintoutHtml(
            new CarePlan(),
            '00000000-0000-4000-8000-000000005678',
            null,
            null,
            'Д-р Іван Петренко'
        );

        $this->assertStringContainsString('Д-р Іван Петренко', $html);
    }

    public function test_build_fallback_printout_html_falls_back_to_dash_without_doctor_name(): void
    {
        // The authenticated user is not a source for the printout: without a caller-supplied
        // name the memo must show a placeholder instead of whoever happens to be logged in.
        $this->actingAs($this->user);

        $html = app(MedicationRequestLifecycleService::class)->buildFallbackPrintoutHtml(
            new CarePlan(),
            '00000000-0000-4000-8000-000000009012'
        );

        $this->assertStringNotContainsString('Іван Петренко', $html);
        $this->assertStringContainsString('—', $html);
    }

    public function test_find_eligible_encounters_requires_today_period_and_current_performer(): void
    {
        $performer = \App\Models\MedicalEvents\Sql\Identifier::create([
            'value' => $this->employee->uuid,
        ]);
        $this->encounter->update(['performer_id' => $performer->id]);
        $this->encounter->period()->create([
            'start' => now()->startOfDay(),
            'end' => now(),
        ]);

        $otherPerformer = \App\Models\MedicalEvents\Sql\Identifier::create([
            'value' => (string) Str::uuid(),
        ]);
        $otherEncounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $this->encounter->episode_id,
            'class_id' => $this->encounter->class_id,
            'type_id' => $this->encounter->type_id,
            'performer_id' => $otherPerformer->id,
            'ehealth_inserted_at' => now(),
        ]);
        $otherEncounter->period()->create([
            'start' => now()->startOfDay(),
            'end' => now(),
        ]);

        $yesterdayEncounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $this->encounter->episode_id,
            'class_id' => $this->encounter->class_id,
            'type_id' => $this->encounter->type_id,
            'performer_id' => $performer->id,
            'ehealth_inserted_at' => now()->subDay(),
        ]);
        $yesterdayEncounter->period()->create([
            'start' => now()->subDay()->startOfDay(),
            'end' => now()->subDay(),
        ]);

        $service = app(MedicationRequestLifecycleService::class);
        $eligible = $service->findEligibleEncountersForEPrescription(
            (int) $this->person->id,
            $this->employee->uuid
        );

        $this->assertCount(1, $eligible);
        $this->assertSame($this->encounter->id, $eligible->first()->id);
    }

    public function test_create_draft_requires_selected_eligible_encounter(): void
    {
        $performer = \App\Models\MedicalEvents\Sql\Identifier::create([
            'value' => $this->employee->uuid,
        ]);
        $this->encounter->update(['performer_id' => $performer->id]);
        $this->encounter->period()->create([
            'start' => now()->startOfDay(),
            'end' => now(),
        ]);

        $service = app(MedicationRequestLifecycleService::class);
        $employeeContext = [
            'employee_id' => $this->employee->id,
            'employee_uuid' => $this->employee->uuid,
            'division_id' => null,
            'legal_entity_uuid' => null,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.eprescription_encounter_required'));

        $service->createCarePlanDraft(
            $this->carePlan->fresh(['person']),
            $this->activity,
            [
                'signature_text' => 'По 1 таблетці 2 рази на день',
                'max_dose_per_administration' => 1,
                'max_dose_per_period' => 2,
                'medication_id' => 'INN-101',
                'medication_qty' => 10,
            ],
            $employeeContext
        );
    }

    public function test_create_draft_rejects_encounter_of_another_performer(): void
    {
        $otherPerformer = \App\Models\MedicalEvents\Sql\Identifier::create([
            'value' => (string) Str::uuid(),
        ]);
        $this->encounter->update(['performer_id' => $otherPerformer->id]);
        $this->encounter->period()->create([
            'start' => now()->startOfDay(),
            'end' => now(),
        ]);

        $service = app(MedicationRequestLifecycleService::class);
        $employeeContext = [
            'employee_id' => $this->employee->id,
            'employee_uuid' => $this->employee->uuid,
            'division_id' => null,
            'legal_entity_uuid' => null,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('care-plan.eprescription_encounter_none'));

        $service->createCarePlanDraft(
            $this->carePlan->fresh(['person']),
            $this->activity,
            [
                'encounter_id' => $this->encounter->id,
                'signature_text' => 'По 1 таблетці 2 рази на день',
                'max_dose_per_administration' => 1,
                'max_dose_per_period' => 2,
                'medication_id' => 'INN-101',
                'medication_qty' => 10,
            ],
            $employeeContext
        );
    }

    public function test_create_draft_persists_selected_eligible_encounter(): void
    {
        $performer = \App\Models\MedicalEvents\Sql\Identifier::create([
            'value' => $this->employee->uuid,
        ]);
        $this->encounter->update(['performer_id' => $performer->id]);
        $this->encounter->period()->create([
            'start' => now()->startOfDay(),
            'end' => now(),
        ]);

        $createdUuid = (string) Str::uuid();
        $mockApi = Mockery::mock('alias:' . \App\Classes\eHealth\Api\MedicationRequest::class);
        $mockApi->shouldReceive('createMedicationRequest')
            ->once()
            ->andReturn([
                'id' => $createdUuid,
                'request_number' => '1111222233334444',
                'status' => 'NEW',
            ]);

        $service = app(MedicationRequestLifecycleService::class);
        $employeeContext = [
            'employee_id' => $this->employee->id,
            'employee_uuid' => $this->employee->uuid,
            'division_id' => null,
            'legal_entity_uuid' => null,
        ];

        $uuid = $service->createCarePlanDraft(
            $this->carePlan->fresh(['person']),
            $this->activity,
            [
                'encounter_id' => $this->encounter->id,
                'signature_text' => 'По 1 таблетці 2 рази на день',
                'max_dose_per_administration' => 1,
                'max_dose_per_period' => 2,
                'medication_id' => 'INN-101',
                'medication_qty' => 10,
                'medication_unit' => 'шт.',
                'inform_with' => 'auth-1|OTP|+380******01',
            ],
            $employeeContext
        );

        $this->assertSame($createdUuid, $uuid);
        $this->assertDatabaseHas('medication_request_requests', [
            'uuid' => $createdUuid,
            'context_id' => $this->encounter->id,
            'based_on_id' => $this->activity->id,
        ]);
    }

    public function test_create_draft_requires_signature_text(): void
    {
        $performer = \App\Models\MedicalEvents\Sql\Identifier::create([
            'value' => $this->employee->uuid,
        ]);
        $this->encounter->update(['performer_id' => $performer->id]);
        $this->encounter->period()->create([
            'start' => now()->startOfDay(),
            'end' => now(),
        ]);

        $service = app(MedicationRequestLifecycleService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.eprescription_signature_required'));

        $service->createCarePlanDraft(
            $this->carePlan->fresh(['person']),
            $this->activity,
            [
                'encounter_id' => $this->encounter->id,
                'signature_text' => '   ',
                'max_dose_per_administration' => 1,
                'max_dose_per_period' => 2,
            ],
            [
                'employee_id' => $this->employee->id,
                'employee_uuid' => $this->employee->uuid,
            ]
        );
    }

    public function test_post_sign_success_message_sms_vs_print(): void
    {
        $service = app(MedicationRequestLifecycleService::class);

        $sms = $service->buildPostSignSuccessMessage(
            '5555666677778888',
            'auth-uuid|OTP|+380******99',
            false
        );
        $this->assertStringContainsString('5555666677778888', $sms);
        $this->assertStringContainsString('СМС', $sms);
        $this->assertStringContainsString('+380******99', $sms);

        $print = $service->buildPostSignSuccessMessage(
            '5555666677778888',
            'auth-uuid|OFFLINE|Документи',
            false
        );
        $this->assertStringContainsString('друкованій інформаційній памʼятці', $print);

        $printDisabled = $service->buildPostSignSuccessMessage(
            '5555666677778888',
            'auth-uuid|OTP|+380******99',
            true
        );
        $this->assertStringContainsString('друкованій інформаційній памʼятці', $printDisabled);
    }

    public function test_remaining_qty_warning_when_leftover_less_than_qty(): void
    {
        $service = app(MedicationRequestLifecycleService::class);

        $this->assertTrue($service->shouldWarnRemainingQty(15.0, 10.0));
        $this->assertFalse($service->shouldWarnRemainingQty(30.0, 10.0));
        $this->assertTrue($service->shouldWarnRemainingQty(10.0, 10.0));

        $message = $service->buildRemainingQtyWarningMessage(15.0, 'шт.');
        $this->assertStringContainsString('15', $message);
        $this->assertStringContainsString('шт.', $message);
        $this->assertStringContainsString('лікаря-спеціаліста', $message);
    }

    public function test_sign_sets_tv_success_and_remaining_warning(): void
    {
        $requestRecord = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'new',
            'medication_id' => 'INN-101',
            'medication_qty' => 10.0,
            'intent' => 'order',
            'based_on_id' => $this->activity->id,
            'context_id' => $this->encounter->id,
            'inform_with' => 'auth-1|OTP|+380******12',
            'request_number' => '9999000011112222',
            'ehealth_payload' => [
                'id' => (string) Str::uuid(),
                'request_number' => '9999000011112222',
            ],
        ]);

        $mockApi = Mockery::mock('alias:' . \App\Classes\eHealth\Api\MedicationRequest::class);
        $mockApi->shouldReceive('signMedicationRequest')
            ->once()
            ->andReturn([
                'status' => 'ACTIVE',
                'request_number' => '9999000011112222',
            ]);

        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldReceive('signData')->once()->andReturn('kep-signed');

        $service = app(MedicationRequestLifecycleService::class);
        $result = $service->signPrescription(
            $this->carePlan->fresh(['person']),
            $requestRecord,
            [
                'password' => '12345678',
                'knedp' => 'acsk_test',
                'signer_tax_id' => '9876543210',
                'request_notification_disabled' => false,
                'medication_unit' => 'шт.',
            ],
            (string) $requestRecord->inform_with,
            15.0
        );

        $this->assertStringContainsString('СМС', $result['success_message']);
        $this->assertTrue($result['show_remaining_qty_warning'] ?? false);
        $this->assertStringContainsString('15', $result['warning_message']);
        $this->assertDatabaseHas('medication_request_requests', [
            'uuid' => $requestRecord->uuid,
            'status' => 'active',
        ]);
    }

    public function test_sign_refuses_a_detached_blob_without_local_kep(): void
    {
        $requestRecord = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'new',
            'medication_id' => 'INN-101',
            'medication_qty' => 10.0,
            'intent' => 'order',
            'based_on_id' => $this->activity->id,
            'context_id' => $this->encounter->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.kep_signature_required'));

        app(MedicationRequestLifecycleService::class)->signPrescription(
            $this->carePlan->fresh(['person']),
            $requestRecord,
            ['signed_medication_request_request' => 'client-blob']
        );
    }

    public function test_sign_refuses_when_activity_remaining_quantity_is_exhausted(): void
    {
        MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'medication_id' => 'INN-101',
            'medication_qty' => 30.0,
            'intent' => 'order',
            'based_on_id' => $this->activity->id,
            'context_id' => $this->encounter->id,
        ]);

        $requestRecord = MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'new',
            'medication_id' => 'INN-101',
            'medication_qty' => 10.0,
            'intent' => 'order',
            'based_on_id' => $this->activity->id,
            'context_id' => $this->encounter->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(__('care-plan.activity_issue_exceeds_remaining', ['remaining' => 0]));

        app(MedicationRequestLifecycleService::class)->signPrescription(
            $this->carePlan->fresh(['person']),
            $requestRecord,
            [
                'password' => '12345678',
                'knedp' => 'acsk_test',
                'signer_tax_id' => '9876543210',
            ]
        );
    }
}
