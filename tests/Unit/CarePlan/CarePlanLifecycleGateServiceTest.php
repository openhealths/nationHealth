<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Services\MedicalEvents\CarePlanLifecycleGateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CarePlanLifecycleGateServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CarePlanLifecycleGateService $service;

    private Employee $employee;

    private LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CarePlanLifecycleGateService();

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Unit',
            'last_name' => 'Doctor',
            'tax_id' => '5566778899',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Unit Doctor',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $party->id,
        ]);
    }

    public function test_detects_open_mrr_mr_service_and_device_requests(): void
    {
        [$carePlan, $activity] = $this->createPlanWithActivity();

        MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $carePlan->person_id,
            'employee_id' => $this->employee->id,
            'status' => 'new',
            'medication_id' => 'INN-1',
            'medication_qty' => 1,
            'intent' => 'order',
            'based_on_id' => $activity->id,
        ]);

        MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $carePlan->person_id,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'medication_id' => 'INN-2',
            'medication_qty' => 1,
            'intent' => 'order',
            'based_on_id' => $activity->id,
        ]);

        ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $carePlan->person_id,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'based_on_id' => $activity->id,
            'priority' => 'routine',
        ]);

        DeviceRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $carePlan->person_id,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'device_id' => 'device-1',
            'quantity' => 1,
            'intent' => 'order',
            'based_on_id' => $activity->id,
            'priority' => 'routine',
        ]);

        MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $carePlan->person_id,
            'employee_id' => $this->employee->id,
            'status' => 'completed',
            'medication_id' => 'INN-3',
            'medication_qty' => 1,
            'intent' => 'order',
            'based_on_id' => $activity->id,
        ]);

        $open = $this->service->findOpenDocumentsForActivity($activity);

        $this->assertCount(4, $open);
        $this->assertTrue($this->service->hasOpenDocumentsForActivity($activity));

        $cancelReason = $this->service->activityStatusChangeBlockReason($activity, 'cancel_activity');
        $completeReason = $this->service->activityStatusChangeBlockReason($activity, 'complete_activity');

        $this->assertNotNull($cancelReason);
        $this->assertStringContainsString('Неможливо відмінити призначення', $cancelReason);
        $this->assertStringContainsString('нова заявка на електронний рецепт', $cancelReason);
        $this->assertStringContainsString('непогашений електронний рецепт', $cancelReason);
        $this->assertStringContainsString('активне електронне направлення', $cancelReason);
        $this->assertStringContainsString('непогашений е-запит на медичні вироби', $cancelReason);

        $this->assertNotNull($completeReason);
        $this->assertStringContainsString('Неможливо завершити призначення', $completeReason);
    }

    public function test_ignores_closed_documents(): void
    {
        [$carePlan, $activity] = $this->createPlanWithActivity();

        foreach (['completed', 'rejected', 'cancelled', 'expired', 'entered-in-error'] as $status) {
            MedicationRequestRequest::create([
                'uuid' => (string) Str::uuid(),
                'person_id' => $carePlan->person_id,
                'employee_id' => $this->employee->id,
                'status' => $status,
                'medication_id' => 'INN-x',
                'medication_qty' => 1,
                'intent' => 'order',
                'based_on_id' => $activity->id,
            ]);
        }

        $this->assertSame([], $this->service->findOpenDocumentsForActivity($activity));
        $this->assertNull($this->service->activityStatusChangeBlockReason($activity, 'cancel_activity'));
    }

    public function test_blocks_plan_cancel_for_scheduled_in_progress_or_completed_activities(): void
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'title' => 'Gate Plan',
            'status' => 'active',
            'period_start' => now()->format('Y-m-d'),
        ]);

        CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'scheduled',
            'kind' => 'service_request',
            'author_id' => $this->employee->id,
        ]);

        CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'cancelled',
            'kind' => 'service_request',
            'author_id' => $this->employee->id,
        ]);

        $reason = $this->service->planCancelBlockReason($carePlan->fresh('activities'));

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Неможливо відмінити план лікування', $reason);
        $this->assertStringContainsString('Заплановано', $reason);

        $carePlan->activities()->where('status', 'scheduled')->update(['status' => 'cancelled']);
        $this->assertNull($this->service->planCancelBlockReason($carePlan->fresh('activities')));

        CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'completed',
            'kind' => 'medication_request',
            'author_id' => $this->employee->id,
        ]);

        $completedBlock = $this->service->planCancelBlockReason($carePlan->fresh('activities'));
        $this->assertNotNull($completedBlock);
        $this->assertStringContainsString('Завершено', $completedBlock);
    }

    public function test_blocks_plan_complete_when_activity_is_not_final(): void
    {
        [$carePlan] = $this->createPlanWithActivity();
        $carePlan->update(['status' => 'active']);
        $carePlan->activities()->update(['status' => 'in_progress']);

        $reason = $this->service->planCompleteBlockReason($carePlan->fresh('activities'));

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Неможливо завершити план лікування', $reason);
        $this->assertStringContainsString('Синхронізувати з ЕСОЗ', $reason);
        $this->assertFalse($this->service->isFinalActivityStatus('processed'));
        $this->assertFalse($this->service->isFinalActivityStatus('in_progress'));
    }

    public function test_blocks_plan_complete_when_the_only_activity_is_processed_job_status(): void
    {
        [$carePlan] = $this->createPlanWithActivity();
        $carePlan->update(['status' => 'active']);
        $carePlan->activities()->update(['status' => 'processed']);

        $reason = $this->service->planCompleteBlockReason($carePlan->fresh('activities'));

        $this->assertNotNull($reason);
        $this->assertStringContainsString(__('care-plan.status.processed'), $reason);
    }

    public function test_allows_plan_complete_when_activities_are_completed_or_cancelled(): void
    {
        [$carePlan] = $this->createPlanWithActivity();
        $carePlan->update(['status' => 'active']);
        $carePlan->activities()->update(['status' => 'completed']);

        $this->assertNull($this->service->planCompleteBlockReason($carePlan->fresh('activities')));

        CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'cancelled',
            'kind' => 'service_request',
            'author_id' => $this->employee->id,
        ]);

        $this->assertNull($this->service->planCompleteBlockReason($carePlan->fresh('activities')));
    }

    public function test_blocks_plan_complete_when_every_activity_is_cancelled(): void
    {
        [$carePlan] = $this->createPlanWithActivity();
        $carePlan->update(['status' => 'active']);
        $carePlan->activities()->update(['status' => 'cancelled']);

        $reason = $this->service->planCompleteBlockReason($carePlan->fresh('activities'));

        $this->assertSame(__('care-plan.cannot_complete_plan_no_completed_activity'), $reason);
    }

    public function test_blocks_plan_cancel_when_the_plan_itself_is_already_cancelled(): void
    {
        [$carePlan] = $this->createPlanWithActivity();
        $carePlan->update(['status' => 'cancelled']);

        $reason = $this->service->planCancelBlockReason($carePlan->fresh());

        $this->assertSame(
            __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => __('care-plan.status.cancelled'),
            ]),
            $reason
        );
    }

    /**
     * @return array{0: CarePlan, 1: CarePlanActivity}
     */
    private function createPlanWithActivity(): array
    {
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'title' => 'Open Docs Plan',
            'status' => 'active',
            'period_start' => now()->format('Y-m-d'),
        ]);

        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'author_id' => $this->employee->id,
        ]);

        return [$carePlan, $activity];
    }
}
