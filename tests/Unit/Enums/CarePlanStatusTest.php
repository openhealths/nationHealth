<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CarePlanStatus;
use Tests\TestCase;

class CarePlanStatusTest extends TestCase
{
    public function test_from_stored_maps_american_canceled_to_cancelled(): void
    {
        $this->assertSame(CarePlanStatus::CANCELLED, CarePlanStatus::fromStored('canceled'));
        $this->assertTrue(CarePlanStatus::fromStored('cancelled')->isTerminal());
    }

    public function test_from_job_response_ignores_processed_job_status(): void
    {
        $status = CarePlanStatus::fromJobResponse(
            ['status' => 'processed', 'result' => ['id' => 'abc']],
            CarePlanStatus::CANCELLED
        );

        $this->assertSame(CarePlanStatus::CANCELLED, $status);
    }

    public function test_from_job_response_prefers_entity_status(): void
    {
        $status = CarePlanStatus::fromJobResponse(
            [
                'status' => 'processed',
                'result' => ['id' => 'abc', 'status' => 'cancelled'],
            ],
            CarePlanStatus::ACTIVE
        );

        $this->assertSame(CarePlanStatus::CANCELLED, $status);
    }

    public function test_label_for_falls_back_to_lang_for_job_status(): void
    {
        $this->assertSame(__('care-plan.status.processed'), CarePlanStatus::labelFor('processed'));
        $this->assertSame(__('care-plan.status.cancelled'), CarePlanStatus::labelFor('cancelled'));
    }
}
