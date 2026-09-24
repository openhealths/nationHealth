<?php

declare(strict_types=1);

namespace Tests\Unit\Employee;

use App\Enums\Employee\RequestStatus;
use App\Models\Employee\EmployeeRequest;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRequestProcessorLatestApprovedTest extends TestCase
{
    #[Test]
    public function partition_keeps_only_newest_approved_edit_per_employee(): void
    {
        $older = new EmployeeRequest([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::NEW,
            'employee_id' => 10,
        ]);
        $older->id = 1;
        $older->created_at = now()->subDay();

        $newer = new EmployeeRequest([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::NEW,
            'employee_id' => 10,
        ]);
        $newer->id = 2;
        $newer->created_at = now();

        $otherEmployee = new EmployeeRequest([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::NEW,
            'employee_id' => 99,
        ]);
        $otherEmployee->id = 3;
        $otherEmployee->created_at = now();

        $processor = app(EmployeeRequestProcessor::class);
        $partition = $processor->partitionLatestApprovedPerEmployee(
            collect([$older, $newer, $otherEmployee])
        );

        $this->assertSame([2, 3], $partition['apply']->pluck('id')->sort()->values()->all());
        $this->assertSame([1], $partition['superseded']->pluck('id')->all());
    }

    #[Test]
    public function partition_keeps_every_create_request_without_employee_id(): void
    {
        $createA = new EmployeeRequest([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::NEW,
            'employee_id' => null,
        ]);
        $createA->id = 10;
        $createA->created_at = now()->subHour();

        $createB = new EmployeeRequest([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::NEW,
            'employee_id' => null,
        ]);
        $createB->id = 11;
        $createB->created_at = now();

        $processor = app(EmployeeRequestProcessor::class);
        $partition = $processor->partitionLatestApprovedPerEmployee(
            collect([$createA, $createB])
        );

        $this->assertSame([10, 11], $partition['apply']->pluck('id')->sort()->values()->all());
        $this->assertTrue($partition['superseded']->isEmpty());
    }
}
